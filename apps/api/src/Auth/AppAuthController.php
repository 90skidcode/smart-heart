<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use PDO;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Input;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Clock;
use SmartHeart\Infra\Secrets;
use SmartHeart\Infra\Tx;

/**
 * POST /app/auth/login — participants and caregivers sign in with their case number only.
 *
 * Every failure (malformed, unknown, replaced, revoked, case ended) gets the same 401, so nobody
 * can learn which numbers exist. 5 failures from one install ID or IP in 15 minutes → 429 for 15
 * minutes; 50 failures across all clients in 10 minutes → security alert. Signing in on a new phone
 * ends every earlier session for that person.
 */
final readonly class AppAuthController
{
    public const FAILED_MESSAGE = 'That case number did not work. Check the card, or call the study team.';

    /** @param callable(string): void $securityAlert */
    public function __construct(
        private PDO $db,
        private TokenService $tokens,
        private Secrets $secrets,
        private AuditLog $audit,
        private Clock $clock,
        private mixed $securityAlert,
    ) {
    }

    public function login(Request $request): Response
    {
        $in = Input::fromJson($request);
        $caseNumber = $in->string('case_number', 32);
        $installId = $in->uuid('install_id');
        $platform = $in->enum('platform', ['android', 'ios']);
        $osVersion = $in->string('os_version', 16, required: false);
        $model = $in->string('device_model', 60, required: false);
        $appVersion = $in->string('app_version', 16);
        $in->validate();

        $policy = AppSettings::signIn($this->db);
        $ip = @inet_pton($request->ip) ?: inet_pton('0.0.0.0');

        if ($this->recentFailures($installId, $ip, $policy['throttle']['window_min']) >= $policy['throttle']['max_failures']) {
            $this->recordAttempt($ip, $installId, 'throttled');
            throw HttpError::tooManyRequests($policy['throttle']['block_min'] * 60);
        }

        $credential = CaseNumber::isWellFormed((string) $caseNumber) ? $this->findCredential((string) $caseNumber) : null;
        $failure = match (true) {
            !CaseNumber::isWellFormed((string) $caseNumber) => 'malformed',
            $credential === null => 'unknown',
            !$credential['usable'] => 'ended',
            default => null,
        };
        if ($failure !== null) {
            $this->fail($request, $ip, $installId, $failure, $credential, $policy);
        }

        $device = [
            'device_id' => $installId,
            'device_label' => substr(trim(($platform === 'ios' ? 'iOS' : 'Android') . ' ' . $osVersion . ($model ? " · $model" : '')), 0, 80),
            'app_version' => $appVersion,
        ];
        $subjectType = $credential['subject_type'];
        $subjectId = (int) $credential['subject_id'];
        $pid = (int) $credential['participant_id'];

        $result = Tx::run($this->db, function () use ($request, $ip, $installId, $credential, $device, $subjectType, $subjectId, $pid, $policy) {
            $now = Clock::sql($this->clock->now());
            $signedOut = $policy['one_device_per_person'] ? $this->tokens->revokeSubject($subjectType, $subjectId, 'new_device') : 0;
            $pair = $this->tokens->open($subjectType, $subjectId, $request, $device, (int) $credential['id']);

            $this->db->prepare('UPDATE app_credentials SET first_login_at = COALESCE(first_login_at, ?), last_login_at = ? WHERE id = ?')
                ->execute([$now, $now, $credential['id']]);
            if ($subjectType === 'participant') {
                $this->db->prepare('UPDATE participants SET app_activated_at = COALESCE(app_activated_at, ?) WHERE id = ?')->execute([$now, $pid]);
            } else {
                $this->db->prepare("UPDATE caregivers SET invite_status = 'active' WHERE id = ? AND invite_status = 'invited'")->execute([$subjectId]);
            }
            $this->recordAttempt($ip, $installId, null, (int) $credential['id']);
            $this->audit->record(['type' => $subjectType, 'id' => $subjectId], 'login', 'app_credentials', (int) $credential['id'], $pid,
                newValues: ['device' => $device['device_label'], 'app_version' => $device['app_version'], 'signed_out_other_device' => $signedOut > 0],
                context: $request->auditContext());

            return $pair + ['signed_out_other_device' => $signedOut > 0];
        });

        $profile = $this->db->prepare('SELECT p.preferred_language, ap.onboarding_step FROM participants p
            LEFT JOIN app_profiles ap ON ap.participant_id = p.id WHERE p.id = ?');
        $profile->execute([$pid]);
        $profile = $profile->fetch();

        return Response::json($result + [
            'subject_type' => $subjectType,
            'onboarding_step' => $subjectType === 'participant' ? $profile['onboarding_step'] : null,
            'preferred_language' => $profile['preferred_language'],
        ]);
    }

    /**
     * The credential for this number under any configured pepper (old peppers keep working after a rotation).
     * `usable` is false when the number or the case has ended.
     */
    private function findCredential(string $caseNumber): ?array
    {
        $where = [];
        $params = [];
        foreach ($this->secrets->peppers as $keyId => $pepper) {
            $where[] = '(c.case_no_hmac = ? AND c.pepper_key_id = ?)';
            array_push($params, CaseNumber::hmac($caseNumber, $pepper), $keyId);
        }
        $stmt = $this->db->prepare("SELECT c.id, c.subject_type, c.subject_id, c.participant_id,
                (c.status = 'active' AND p.arm = 'intervention' AND p.status NOT IN ('withdrawn','lost_to_follow_up','completed')) AS usable
            FROM app_credentials c JOIN participants p ON p.id = c.participant_id
            WHERE " . implode(' OR ', $where) . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['usable'] = (bool) $row['usable'];
        return $row;
    }

    private function recentFailures(string $installId, string $ip, int $windowMin): int
    {
        $since = Clock::sql($this->clock->now()->modify("-$windowMin minutes"));
        $count = static function (PDO $db, string $column, string $value) use ($since): int {
            $stmt = $db->prepare("SELECT COUNT(*) FROM app_login_attempts WHERE $column = ? AND succeeded = 0 AND created_at > ?");
            $stmt->execute([$value, $since]);
            return (int) $stmt->fetchColumn();
        };
        return max($count($this->db, 'install_id', $installId), $count($this->db, 'ip', $ip));
    }

    private function recordAttempt(string $ip, string $installId, ?string $failure, ?int $credentialId = null): void
    {
        $this->db->prepare('INSERT INTO app_login_attempts (ip, install_id, succeeded, failure, credential_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$ip, $installId, $failure === null ? 1 : 0, $failure, $credentialId, Clock::sql($this->clock->now())]);
    }

    /** Records the failure, raises the attack alert at the threshold, and throws the uniform 401. */
    private function fail(Request $request, string $ip, string $installId, string $failure, ?array $credential, array $policy): never
    {
        Tx::run($this->db, function () use ($request, $ip, $installId, $failure, $credential) {
            $this->recordAttempt($ip, $installId, $failure);
            $this->audit->record(
                ['type' => $credential['subject_type'] ?? 'participant', 'id' => isset($credential['subject_id']) ? (int) $credential['subject_id'] : null],
                'login_failed', 'app_credentials', isset($credential['id']) ? (int) $credential['id'] : null,
                isset($credential['participant_id']) ? (int) $credential['participant_id'] : null,
                reason: "case-number sign-in failed: $failure", context: $request->auditContext(),
            );
        });

        $alert = $policy['attack_alert'];
        $since = Clock::sql($this->clock->now()->modify("-{$alert['window_min']} minutes"));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM app_login_attempts WHERE succeeded = 0 AND created_at > ?');
        $stmt->execute([$since]);
        if ((int) $stmt->fetchColumn() === (int) $alert['failures']) { // once, as the threshold is crossed
            ($this->securityAlert)("SECURITY: {$alert['failures']} failed case-number sign-ins in {$alert['window_min']} minutes across all clients");
        }
        throw HttpError::unauthorized(self::FAILED_MESSAGE);
    }
}
