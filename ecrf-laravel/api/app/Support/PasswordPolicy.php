<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PasswordPolicy
{
    /** Returns an error message, or null when the password is acceptable. */
    public static function check(string $password, ?User $user = null): ?string
    {
        $min = config('smartheart.security.password_min_length', 10);
        if (mb_strlen($password) < $min) {
            return "Password must be at least {$min} characters.";
        }
        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            return 'Password must contain both letters and numbers.';
        }
        if ($user && (stripos($password, explode('@', $user->email)[0]) !== false)) {
            return 'Password must not contain your email name.';
        }
        if ($user && Hash::check($password, $user->password)) {
            return 'New password must be different from the current one.';
        }

        return null;
    }
}
