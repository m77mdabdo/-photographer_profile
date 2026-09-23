<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Theme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the visitor's theme before the view renders.
 *
 * This is why there is no flash of the wrong palette: the decision is made on
 * the server, written into the opening <html> tag, and is therefore in force
 * on the first paint. A script that reads localStorage after parse — the usual
 * approach — always paints once in the wrong colours first.
 */
class ResolveTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $theme = Theme::fromCookie($request->cookie(Theme::COOKIE));

        View::share('theme', $theme);

        $response = $next($request);

        /*
         * Caching. The cookie changes the rendered HTML, so a shared cache
         * must not serve one visitor's palette to another. `Vary: Cookie` is
         * the correct signal, and it is deliberately narrow:
         *
         *  - Vary: Cookie tells a shared cache to key on the cookie header.
         *    Most CDNs treat that as "do not cache at the edge", which is the
         *    safe outcome for a page that genuinely differs.
         *  - The alternative, no Vary at all, is the damaging one: a proxy
         *    would happily hand a dark page to someone who chose light.
         *
         * Only responses that actually vary get the header. A static asset
         * served through the same stack is untouched, so the CSS, the fonts
         * and the images stay fully cacheable — which is where the bytes are.
         */
        if ($this->varies($response)) {
            $existing = $response->headers->get('Vary');

            if ($existing === null || ! str_contains(strtolower($existing), 'cookie')) {
                $response->headers->set('Vary', trim(($existing ? $existing.', ' : '').'Cookie', ', '));
            }
        }

        return $response;
    }

    /**
     * Only HTML varies by theme. Everything else is byte-identical either way.
     */
    private function varies(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
