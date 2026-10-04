<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use PDO;
use RuntimeException;

/** Settings staff can change in Admin › Settings (app_settings table). */
final class AppSettings
{
    /**
     * The app_signin policy: case-number format, throttle, attack alert, token lifetimes, issue roles.
     * @return array<string, mixed>
     */
    public static function signIn(PDO $db): array
    {
        $json = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'app_signin'")->fetchColumn();
        if ($json === false) {
            throw new RuntimeException('app_settings.app_signin is missing');
        }
        return json_decode((string) $json, true, 16, JSON_THROW_ON_ERROR);
    }
}
