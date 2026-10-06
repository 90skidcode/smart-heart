<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use SmartHeart\Http\Input;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Infra\AuditLog;

/** Refresh and logout, shared by staff, participants and caregivers. */
final readonly class SessionController
{
    public function __construct(private TokenService $tokens, private Authenticator $auth, private AuditLog $audit)
    {
    }

    /** POST /auth/refresh */
    public function refresh(Request $request): Response
    {
        $in = Input::fromJson($request);
        $token = $in->string('refresh_token', 128);
        $in->validate();
        return Response::json($this->tokens->refresh((string) $token, $request));
    }

    /** POST /auth/logout — ends this session (the whole refresh family). */
    public function logout(Request $request): Response
    {
        $me = $this->auth->any($request);
        $this->tokens->revokeFamily($me->sessionId, 'logout');
        $this->audit->record($me->actor(), 'logout', participantId: $me->participantId, context: $request->auditContext());
        return Response::noContent();
    }
}
