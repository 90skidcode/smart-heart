<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class Audit
{
    /**
     * Write one audit entry. $attrs may contain: entity_type, entity_id, participant_id,
     * form_code, field, old_value, new_value, reason, meta, user (to override the current user).
     */
    public static function log(string $action, array $attrs = []): AuditLog
    {
        $user = $attrs['user'] ?? Auth::user();
        unset($attrs['user']);

        foreach (['old_value', 'new_value'] as $k) {
            if (array_key_exists($k, $attrs)) {
                $attrs[$k] = self::str($attrs[$k]);
            }
        }

        return AuditLog::create(array_merge([
            'created_at' => now(),
            'user_id' => $user?->id,
            'user_name' => $user ? "{$user->name} ({$user->email})" : null,
            'action' => $action,
            'ip_address' => request()?->ip(),
        ], $attrs));
    }

    /** Log every field that differs between two data arrays. */
    public static function diff(string $action, array $old, array $new, array $context, array $reasons = []): int
    {
        $count = 0;
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $a = $old[$field] ?? null;
            $b = $new[$field] ?? null;
            if (self::str($a) === self::str($b)) {
                continue;
            }
            self::log($action, $context + [
                'field' => $field,
                'old_value' => $a,
                'new_value' => $b,
                'reason' => $reasons[$field] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    public static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return (string) $v;
    }
}
