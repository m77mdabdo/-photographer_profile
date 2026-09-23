<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory for the admin area.
 *
 * A confirmed second factor is required, not merely an enrolled one:
 * two_factor_confirmed_at is only set once the user has proved they can
 * produce a valid code.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()
                ->route('admin.two-factor.setup')
                ->with('status', 'Two-factor authentication is required before you can continue.');
        }

        return $next($request);
    }
}
