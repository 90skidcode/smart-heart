<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: screen:participants,write */
class RequireScreen
{
    public function handle(Request $request, Closure $next, string $screen, string $level = 'read'): Response
    {
        $user = $request->user();
        if (! $user || ! $user->canScreen($screen, $level)) {
            return response()->json([
                'message' => "Your role does not have {$level} access to this screen.",
                'code' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
