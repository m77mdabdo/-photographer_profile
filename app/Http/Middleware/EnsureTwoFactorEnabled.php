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
            /*
             * THE FLASH SAYS WHY THEY MOVED, NOT WHAT THE PAGE IS.
             *
             * It used to read "Two-factor authentication is required before
             * you can continue", which is what the destination page's own
             * heading already says — so the page showed the same fact three
             * times, once word-for-word duplicated because the layout renders
             * session('status') as well.
             *
             * A redirect's flash has exactly one piece of information the
             * destination cannot have: where the visitor was trying to go.
             * That is what it carries now, and both panels inherit it.
             */
            return redirect()
                ->route('admin.two-factor.setup')
                ->with('status', __('You were sent here from :path.', [
                    'path' => $request->path() === '/' ? 'the admin' : '/'.ltrim($request->path(), '/'),
                ]));
        }

        return $next($request);
    }
}
