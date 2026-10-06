<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for staff. Tokens are stored hashed (sha256).
 * Sessions end after N idle minutes (GCP: automatic logoff) or a hard maximum.
 */
class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (! $plain) {
            return response()->json(['message' => 'Not signed in.', 'code' => 'unauthenticated'], 401);
        }

        $token = ApiToken::with('user.role.permissions')->where('token_hash', hash('sha256', $plain))->first();
        $idle = config('smartheart.security.idle_minutes');

        if (! $token || $token->expires_at->isPast() || $token->last_used_at->lt(now()->subMinutes($idle))) {
            $token?->delete();

            return response()->json(['message' => 'Your session has expired. Please sign in again.', 'code' => 'session_expired'], 401);
        }

        $user = $token->user;
        if (! $user || ! $user->is_active) {
            $token->delete();

            return response()->json(['message' => 'Account is inactive.', 'code' => 'inactive'], 401);
        }

        // Forced password change: only allow the change-password and me/logout endpoints.
        if ($user->must_change_password && ! $request->is('api/auth/*')) {
            return response()->json(['message' => 'You must change your password first.', 'code' => 'password_change_required'], 403);
        }

        // Avoid a write on every request: refresh at most once a minute.
        if ($token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        Auth::setUser($user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
