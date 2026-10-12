<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TwoFactorEnforcement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory for the admin area.
 *
 * A confirmed second factor is required, not merely an enrolled one:
 * two_factor_confirmed_at is only set once the user has proved they can
 * produce a valid code.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * UNLESS THE ENVIRONMENT SAYS OTHERWISE, WHICH ONLY DEVELOPMENT MAY SAY.
 *
 * config('auth.require_two_factor') defaults to true and cannot be false in
 * production — the application refuses to boot. With it off this middleware
 * passes straight through; with it on, every line below behaves exactly as it
 * did before the switch existed.
 *
 * The check is HERE rather than in the two middleware stacks that use this
 * class, because /admin and /studio both name it and a condition written
 * twice is a condition that will disagree with itself. It is also here rather
 * than at registration: removing the middleware when the config is false
 * would mean the guard's presence depended on when the container booted, and
 * config:cache would decide the security of the site.
 * ───────────────────────────────────────────────────────────────────────────
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * STILL 403 FOR A GUEST, EVEN WITH ENFORCEMENT OFF.
         *
         * This runs after Authenticate in both stacks, so a null user here
         * means something is wired wrongly rather than that somebody is
         * signed out. Letting that through because the second factor is
         * optional would turn a configuration mistake into an open admin,
         * which is the opposite of what the switch is for.
         */
        if ($user === null) {
            abort(403);
        }

        if (! TwoFactorEnforcement::required()) {
            return $next($request);
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
