<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin area gate: the visitor must hold a role that belongs in the studio.
 *
 * A user whose role column holds anything unrecognised fails closed — the
 * cast yields null and the in_array check rejects it.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if (! in_array($user->role, [UserRole::Owner, UserRole::Editor], true)) {
            abort(403);
        }

        return $next($request);
    }
}
