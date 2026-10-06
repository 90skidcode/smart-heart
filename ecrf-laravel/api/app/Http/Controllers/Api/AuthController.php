<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordPolicy;
use App\Support\Screens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $sec = config('smartheart.security');
        $user = User::with('role.permissions')->where('email', strtolower($data['email']))->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            Audit::log('login_blocked', ['user' => $user, 'meta' => ['locked_until' => $user->locked_until->toIso8601String()]]);

            return response()->json(['message' => 'Account locked after repeated failed sign-ins. Try again after '.$user->locked_until->format('H:i').' or ask the administrator.'], 423);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $user->failed_attempts++;
                if ($user->failed_attempts >= $sec['lockout_attempts']) {
                    $user->locked_until = now()->addMinutes($sec['lockout_minutes']);
                    $user->failed_attempts = 0;
                }
                $user->save();
            }
            Audit::log('login_failed', ['user' => $user, 'meta' => ['email' => $data['email']]]);

            return response()->json(['message' => 'Incorrect email or password.'], 422);
        }

        if (! $user->is_active) {
            Audit::log('login_failed', ['user' => $user, 'meta' => ['reason' => 'inactive']]);

            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        $plain = Str::random(64);
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'last_used_at' => now(),
            'expires_at' => now()->addHours($sec['max_session_hours']),
        ]);
        Audit::log('login', ['user' => $user]);

        return response()->json(['token' => $plain] + $this->mePayload($user));
    }

    public function me(Request $request)
    {
        return response()->json($this->mePayload($request->user()));
    }

    public function logout(Request $request)
    {
        Audit::log('logout');
        $request->attributes->get('api_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.', 'errors' => ['current_password' => ['Current password is incorrect.']]], 422);
        }
        if ($err = PasswordPolicy::check($data['new_password'], $user)) {
            return response()->json(['message' => $err, 'errors' => ['new_password' => [$err]]], 422);
        }
        $user->forceFill([
            'password' => $data['new_password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
        // End every other session for this user.
        $current = $request->attributes->get('api_token');
        ApiToken::where('user_id', $user->id)->where('id', '!=', $current?->id)->delete();
        Audit::log('password_changed', ['entity_type' => 'user', 'entity_id' => $user->id]);

        return response()->json(['ok' => true] + $this->mePayload($user->fresh('role.permissions')));
    }

    private function mePayload(User $user): array
    {
        $matrix = $user->permissionMatrix();
        $perms = [];
        foreach (Screens::keys() as $k) {
            $perms[$k] = $matrix[$k] ?? ['read' => false, 'write' => false];
        }

        return [
            'user' => $user->toPublic(),
            'permissions' => $perms,
            'idle_minutes' => config('smartheart.security.idle_minutes'),
            'study' => ['name' => config('smartheart.study_name'), 'site' => config('smartheart.site_name')],
        ];
    }
}
