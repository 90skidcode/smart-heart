<?php

declare(strict_types=1);

namespace SmartHeart\AppAccess;

use PDO;
use PDOException;
use RuntimeException;
use SmartHeart\Auth\Authenticator;
use SmartHeart\Auth\CaseNumber;
use SmartHeart\Auth\Principal;
use SmartHeart\Auth\TokenService;
use SmartHeart\Config;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Input;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Clock;
use SmartHeart\Infra\Secrets;
use SmartHeart\Infra\Tx;

/**
 * Staff issue, re-issue, revoke and sign out case numbers (App Access in the contract).
 * The plain number exists only in the issue response: it is never stored, logged or audited.
 * Blinded roles get 403 everywhere here, because a case number reveals the intervention arm.
 */
final readonly class CaseNumberController
{
    private const MAX_HMAC_RETRIES = 5;

    public function __construct(
        private PDO $db,
        private Authenticator $auth,
        private TokenService $tokens,
        private Secrets $secrets,
        private AuditLog $audit,
        private Clock $clock,
        private Config $config,
    ) {
    }

    /** GET /participants/{participantId}/case-numbers */
    public function list(Request $request, array $params): Response
    {
        $me = $this->staff($request, 'app_access.view');
        $participant = $this->participant($me, $params);

        $stmt = $this->db->prepare('SELECT c.*, cg.relation, u.full_name AS issued_by_name FROM app_credentials c
            LEFT JOIN caregivers cg ON c.subject_type = \'caregiver\' AND cg.id = c.subject_id
            JOIN users u ON u.id = c.issued_by
            WHERE c.participant_id = ? ORDER BY c.subject_type DESC, c.subject_id, c.version DESC');
        $stmt->execute([$participant['id']]);

        $sessions = $this->db->prepare('SELECT device_label, app_version, MIN(created_at) AS signed_in_at, MAX(last_used_at) AS last_used_at
            FROM auth_refresh_tokens WHERE credential_id = ? GROUP BY family_id, device_label, app_version
            HAVING SUM(revoked_at IS NULL AND expires_at > ?) > 0 ORDER BY signed_in_at');
        $now = Clock::sql($this->clock->now());

        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $sessions->execute([$c['id'], $now]);
            $out[] = [
                'id' => (int) $c['id'],
                'subject_type' => $c['subject_type'],
                'caregiver_id' => $c['subject_type'] === 'caregiver' ? (int) $c['subject_id'] : null,
                'caregiver_relation' => $c['relation'],
                'hint' => $c['hint'],
                'version' => (int) $c['version'],
                'status' => $c['status'],
                'issued_at' => Clock::isoFromSql($c['issued_at']),
                'issued_by' => $c['issued_by_name'],
                'issue_reason' => $c['issue_reason'],
                'first_login_at' => Clock::isoFromSql($c['first_login_at']),
                'last_login_at' => Clock::isoFromSql($c['last_login_at']),
                'ended_at' => Clock::isoFromSql($c['ended_at']),
                'end_reason' => $c['end_reason'],
                'sessions' => array_map(static fn(array $s) => [
                    'device_label' => $s['device_label'],
                    'app_version' => $s['app_version'],
                    'signed_in_at' => Clock::isoFromSql($s['signed_in_at']),
                    'last_used_at' => Clock::isoFromSql($s['last_used_at']),
                ], $sessions->fetchAll()),
            ];
        }
        return Response::json($out);
    }

    /** POST /participants/{participantId}/case-numbers — issue, or re-issue with a reason. */
    public function issue(Request $request, array $params): Response
    {
        $me = $this->staff($request, 'app_access.issue');
        $in = Input::fromJson($request);
        $subjectType = $in->enum('subject_type', ['participant', 'caregiver']);
        $caregiverId = $in->int('caregiver_id', required: $subjectType === 'caregiver');
        $reason = $in->string('reason', 255, required: false);
        $in->validate();

        $issued = Tx::run($this->db, function () use ($me, $params, $subjectType, $caregiverId, $reason, $request) {
            $participant = $this->participant($me, $params, forUpdate: true);
            if ($participant['arm'] !== 'intervention' || $participant['status'] !== 'randomised') {
                throw new HttpError(409, 'conflict', 'Cannot issue a case number',
                    'Case numbers are only for randomised intervention-arm participants who have not withdrawn or completed.');
            }
            $subjectId = (int) $participant['id'];
            if ($subjectType === 'caregiver') {
                $cg = $this->db->prepare('SELECT id FROM caregivers WHERE id = ? AND participant_id = ? AND invite_status <> \'revoked\' FOR UPDATE');
                $cg->execute([$caregiverId, $participant['id']]);
                $subjectId = (int) ($cg->fetchColumn() ?: throw new HttpError(409, 'conflict', 'Caregiver not linked', 'That caregiver is not linked to this participant, or was removed.'));
            }

            $previous = $this->db->prepare('SELECT id FROM app_credentials WHERE subject_type = ? AND subject_id = ? AND status = \'active\' FOR UPDATE');
            $previous->execute([$subjectType, $subjectId]);
            $previousId = $previous->fetchColumn() ?: null;
            if ($previousId !== null && $reason === null) {
                throw HttpError::validation([['field' => 'reason', 'code' => 'required', 'message' => 'Say why the number is being replaced, e.g. "Card lost"']]);
            }

            $now = Clock::sql($this->clock->now());
            $revoked = 0;
            if ($previousId !== null) {
                $this->db->prepare("UPDATE app_credentials SET status = 'replaced', ended_at = ?, ended_by = ?, end_reason = 'replaced' WHERE id = ?")
                    ->execute([$now, $me->id, $previousId]);
                $revoked = $this->tokens->revokeCredential((int) $previousId, 'case_number_reissued');
            }

            $version = $this->db->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM app_credentials WHERE subject_type = ? AND subject_id = ?');
            $version->execute([$subjectType, $subjectId]);
            $version = (int) $version->fetchColumn();

            [$credentialId, $number] = $this->insertNewNumber((int) $participant['id'], $subjectType, $subjectId, $version, $me, $reason, $now);

            if ($subjectType === 'participant') {
                // First issue ticks the task; a re-issue keeps who ticked it first. `done` is assigned last on purpose.
                $this->db->prepare("INSERT INTO post_randomisation_tasks (participant_id, task_code, done, done_by, done_at)
                    VALUES (?, 'APP_ACCOUNT', 1, ?, ?) AS new ON DUPLICATE KEY UPDATE
                    done_by = IF(post_randomisation_tasks.done = 1, post_randomisation_tasks.done_by, new.done_by),
                    done_at = IF(post_randomisation_tasks.done = 1, post_randomisation_tasks.done_at, new.done_at),
                    done = 1")->execute([$participant['id'], $me->id, $now]);
            } else {
                $this->db->prepare("UPDATE caregivers SET invite_status = 'invited' WHERE id = ?")->execute([$subjectId]);
            }

            $this->audit->record($me->actor(), 'create', 'app_credentials', $credentialId, (int) $participant['id'],
                newValues: ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'hint' => CaseNumber::hint($number),
                    'version' => $version, 'replaced_credential_id' => $previousId === null ? null : (int) $previousId,
                    'sessions_revoked' => $revoked],
                reason: $reason, context: $request->auditContext());

            return [
                'credential_id' => $credentialId,
                'case_number' => $number,
                'subject_type' => $subjectType,
                'caregiver_id' => $subjectType === 'caregiver' ? $subjectId : null,
                'hint' => CaseNumber::hint($number),
                'version' => $version,
                'replaced_credential_id' => $previousId === null ? null : (int) $previousId,
                'sessions_revoked' => $revoked,
                'issued_at' => Clock::iso($this->clock->now()),
                'card' => $this->card($participant['preferred_language']),
            ];
        });
        return Response::json($issued, 201);
    }

    /** POST /participants/{participantId}/case-numbers/{credentialId}/revoke */
    public function revoke(Request $request, array $params): Response
    {
        $me = $this->staff($request, 'app_access.issue');
        $in = Input::fromJson($request);
        $reason = $in->string('reason', 255);
        $in->validate();

        Tx::run($this->db, function () use ($me, $params, $reason, $request) {
            $participant = $this->participant($me, $params);
            $c = $this->credential((int) $participant['id'], $params, forUpdate: true);
            if ($c['status'] !== 'active') {
                throw new HttpError(409, 'conflict', 'Already ended', "This case number is already {$c['status']}.");
            }
            $this->db->prepare("UPDATE app_credentials SET status = 'revoked', ended_at = ?, ended_by = ?, end_reason = ? WHERE id = ?")
                ->execute([Clock::sql($this->clock->now()), $me->id, $reason, $c['id']]);
            $revoked = $this->tokens->revokeCredential((int) $c['id'], 'case_number_revoked');
            if ($c['subject_type'] === 'caregiver') {
                $this->db->prepare("UPDATE caregivers SET invite_status = 'revoked' WHERE id = ?")->execute([$c['subject_id']]);
            }
            $this->audit->record($me->actor(), 'update', 'app_credentials', (int) $c['id'], (int) $participant['id'],
                ['status' => 'active'], ['status' => 'revoked', 'sessions_revoked' => $revoked], $reason, $request->auditContext());
        });
        return Response::noContent();
    }

    /** POST /participants/{participantId}/case-numbers/{credentialId}/sign-out — the number keeps working. */
    public function signOut(Request $request, array $params): Response
    {
        $me = $this->staff($request, 'app_access.issue');
        Tx::run($this->db, function () use ($me, $params, $request) {
            $participant = $this->participant($me, $params);
            $c = $this->credential((int) $participant['id'], $params);
            $revoked = $this->tokens->revokeCredential((int) $c['id'], 'staff_sign_out');
            $this->audit->record($me->actor(), 'update', 'app_credentials', (int) $c['id'], (int) $participant['id'],
                newValues: ['sessions_revoked' => $revoked], reason: 'Staff signed out the phone', context: $request->auditContext());
        });
        return Response::noContent();
    }

    /** @return array{0: int, 1: string} credential id and the plain number (returned once, never stored) */
    private function insertNewNumber(int $participantId, string $subjectType, int $subjectId, int $version, Principal $me, ?string $reason, string $now): array
    {
        $keyId = $this->secrets->currentPepperId;
        $insert = $this->db->prepare('INSERT INTO app_credentials (participant_id, subject_type, subject_id, case_no_hmac, pepper_key_id,
                hint, version, issued_by, issued_at, issue_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($attempt = 1; $attempt <= self::MAX_HMAC_RETRIES; $attempt++) {
            $number = CaseNumber::generate();
            try {
                $insert->execute([$participantId, $subjectType, $subjectId, CaseNumber::hmac($number, $this->secrets->peppers[$keyId]),
                    $keyId, CaseNumber::hint($number), $version, $me->id, $now, $reason]);
                return [(int) $this->db->lastInsertId(), $number];
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062 || !str_contains($e->getMessage(), 'uq_cred_hmac')) {
                    throw $e;
                }
                // A number that was issued before (numbers are never reused): draw again.
            }
        }
        throw new RuntimeException('Could not draw an unused case number after ' . self::MAX_HMAC_RETRIES . ' attempts');
    }

    /**
     * Card text in both languages: the card is printed bilingual, with the participant's preferred
     * language (`primary_language`) shown first. The Tamil wording is a draft awaiting native-speaker review.
     */
    private function card(string $language): array
    {
        return [
            'primary_language' => $language === 'ta' ? 'ta' : 'en',
            'heading_en' => 'Your SMART-HEART case number',
            'heading_ta' => 'உங்கள் SMART-HEART உள்நுழைவு எண்',
            'instructions_en' => 'Open the SHI app and type this number. Keep this card safe. If you lose it, call the study team for a new one.',
            'instructions_ta' => 'SHI செயலியைத் திறந்து இந்த எண்ணை உள்ளிடவும். இந்த அட்டையைப் பாதுகாப்பாக வைத்திருங்கள். தொலைந்துவிட்டால், புதிய எண்ணுக்கு ஆய்வுக் குழுவை அழைக்கவும்.',
            'helpline' => $this->config->studyHelpline,
        ];
    }

    private function staff(Request $request, string $permission): Principal
    {
        $me = $this->auth->staff($request);
        if ($me->blinded) {
            throw HttpError::forbidden('Blinded roles cannot see app access, because it reveals the allocation');
        }
        $me->require($permission);
        return $me;
    }

    /** The participant at the caller's site, or 404 (other sites' participants do not exist for this caller). */
    private function participant(Principal $me, array $params, bool $forUpdate = false): array
    {
        $id = self::id($params['participantId'] ?? '');
        $stmt = $this->db->prepare('SELECT id, site_id, status, arm, preferred_language FROM participants WHERE id = ? AND site_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$id, $me->siteId]);
        return $stmt->fetch() ?: throw HttpError::notFound('No such participant');
    }

    private function credential(int $participantId, array $params, bool $forUpdate = false): array
    {
        $stmt = $this->db->prepare('SELECT id, subject_type, subject_id, status FROM app_credentials WHERE id = ? AND participant_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([self::id($params['credentialId'] ?? ''), $participantId]);
        return $stmt->fetch() ?: throw HttpError::notFound('No such case number for this participant');
    }

    private static function id(string $value): int
    {
        return ctype_digit($value) && strlen($value) <= 18 ? (int) $value : throw HttpError::notFound();
    }
}
