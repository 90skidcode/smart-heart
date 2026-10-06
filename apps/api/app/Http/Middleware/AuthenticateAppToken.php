<?php

namespace App\Http\Middleware;

use App\Models\AppToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the participant app (separate from staff tokens).
 * auth.app        any active app user (participant or caregiver)
 * auth.app:write  participant only — caregivers are read-only
 * Tokens slide: each use extends them, so a phone in daily use stays signed in.
 */
class AuthenticateAppToken
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? AppToken::with('appUser.participant')->where('token_hash', hash('sha256', $plain))->first() : null;
        if (! $token || $token->expires_at->isPast()) {
            $token?->delete();

            return response()->json(['message' => 'Please verify your mobile number again.', 'code' => 'app_signed_out'], 401);
        }
        $user = $token->appUser;
        $p = $user?->participant;
        if (! $user || $user->status !== 'active' || ! $p || $p->status === 'withdrawn' || $p->arm !== 'intervention') {
            $token->delete();

            return response()->json(['message' => 'App access has ended. Please contact the study team.', 'code' => 'app_access_ended'], 401);
        }
        if ($mode === 'write' && $user->isCaregiver()) {
            return response()->json(['message' => 'Caregivers can view but not change information.', 'code' => 'read_only'], 403);
        }
        if ($token->last_used_at->lt(now()->subHour())) {
            $token->forceFill(['last_used_at' => now(), 'expires_at' => now()->addDays(config('smartheart.app.token_days'))])->save();
            $user->forceFill(['last_seen_at' => now()])->save();
        }
        $request->attributes->set('app_user', $user);
        $request->attributes->set('app_token', $token);

        return $next($request);
    }
}
