<?php

namespace App\Services\App;

use App\Models\AppDevice;
use App\Models\AppUser;
use App\Models\PushLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push notifications through Firebase Cloud Messaging (HTTP v1), signed with the service-account key
 * (RS256 JWT → OAuth access token). No health details are ever put in a notification: the lock screen
 * shows only a generic line, and the app fetches the data after the person opens it.
 */
class PushService
{
    /** Notification wording. TAMIL IS A DRAFT pending native-speaker review. */
    public const TEXTS = [
        'meds_updated' => [
            'en' => ['SMART-HEART', 'Your medicine list has been updated. Open the app to see it.'],
            'ta' => ['ஸ்மார்ட் ஹார்ட்', 'உங்கள் மருந்து பட்டியல் புதுப்பிக்கப்பட்டது. பார்க்க செயலியைத் திறக்கவும்.'],
        ],
        'content_new' => [
            'en' => ['SMART-HEART', 'There is something new to read in the app.'],
            'ta' => ['ஸ்மார்ட் ஹார்ட்', 'செயலியில் படிக்க புதிய தகவல் உள்ளது.'],
        ],
        'sync_nudge' => [
            'en' => ['SMART-HEART', 'Please open the app so your readings can be sent to the study team.'],
            'ta' => ['ஸ்மார்ட் ஹார்ட்', 'உங்கள் அளவீடுகள் ஆய்வுக் குழுவிற்கு அனுப்பப்பட செயலியைத் திறக்கவும்.'],
        ],
    ];

    public function configured(): bool
    {
        return (bool) $this->credentials();
    }

    /** Send one kind of message to every device of the given app users. Failures are logged, never thrown. */
    public function send(iterable $users, string $kind, array $data = []): int
    {
        $sent = 0;
        foreach ($users as $u) {
            /** @var AppUser $u */
            [$title, $body] = self::TEXTS[$kind][$u->lang] ?? self::TEXTS[$kind]['en'];
            if (! $this->configured()) {
                PushLog::create(['app_user_id' => $u->id, 'kind' => $kind, 'title' => $title, 'status' => 'skipped', 'error' => 'FIREBASE_CREDENTIALS not set']);

                continue;
            }
            foreach ($u->devices as $d) {
                $err = $this->deliver($d, $title, $body, ['kind' => $kind] + $data);
                PushLog::create(['app_user_id' => $u->id, 'kind' => $kind, 'title' => $title, 'status' => $err ? 'failed' : 'sent', 'error' => $err]);
                $sent += $err ? 0 : 1;
            }
        }

        return $sent;
    }

    private function deliver(AppDevice $d, string $title, string $body, array $data): ?string
    {
        try {
            $c = $this->credentials();
            $res = Http::timeout(10)->withToken($this->accessToken())->post(
                "https://fcm.googleapis.com/v1/projects/{$c['project_id']}/messages:send",
                ['message' => ['token' => $d->fcm_token, 'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data), 'android' => ['priority' => 'high']]],
            );
            if ($res->successful()) {
                return null;
            }
            if ($res->status() === 404 || str_contains($res->body(), 'UNREGISTERED')) {
                $d->delete(); // app uninstalled or token rotated

                return 'Device token no longer valid (removed).';
            }

            return 'FCM '.$res->status().': '.mb_substr($res->body(), 0, 300);
        } catch (\Throwable $e) {
            Log::warning('Push failed: '.$e->getMessage());

            return mb_substr($e->getMessage(), 0, 300);
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm_access_token', 50 * 60, function () {
            $c = $this->credentials();
            $now = time();
            $enc = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
            $unsigned = $enc(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$enc([
                'iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]);
            openssl_sign($unsigned, $sig, $c['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
            $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token',
                ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
            if (! $res->successful()) {
                throw new \RuntimeException('Could not get an FCM access token: '.$res->status());
            }

            return $res->json('access_token');
        });
    }

    private function credentials(): ?array
    {
        $path = (string) config('smartheart.app.firebase_credentials');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }
        $c = json_decode((string) file_get_contents($path), true);

        return isset($c['client_email'], $c['private_key'], $c['project_id']) ? $c : null;
    }
}
