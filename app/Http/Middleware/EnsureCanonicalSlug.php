<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Concerns\Translatable;
use App\Support\Locale;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An English slug under /ar redirects to the Arabic one. It does not 404.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE DECISION, AND WHY IT IS A 301
 *
 * Once slugs are per-locale, `/ar/work/salt-line` and `/ar/work/خط-الملح` can
 * both identify the same project — the first by the English slug, the second
 * by the Arabic one. Three options:
 *
 *   404        Honest about the address being wrong, and wrong about
 *              everything else. The record exists, the reader asked for it by
 *              a name it genuinely has, and the most common way to arrive here
 *              is the language switcher: it preserves the path, so switching
 *              to Arabic on an English project page produces exactly this URL.
 *              A 404 would make the switcher look broken on every detail page
 *              until every slug is translated.
 *
 *   200        Serve the page at whichever address was asked for. This is the
 *              worst option: two URLs, same content, and a canonical tag that
 *              contradicts the address it was fetched from — which is the
 *              precise failure the previous task's tests forbid.
 *
 *   301        Chosen. One address per record per locale, link equity
 *              preserved, the canonical always agrees with the URL that served
 *              it, and the switcher lands the reader on the right page rather
 *              than on an apology.
 *
 * 301 rather than 302 because the mapping is a fact about the content, not
 * about the visitor: /ar/work/salt-line is not a temporarily wrong address, it
 * is the English name for a page whose Arabic name is permanent.
 *
 * UNTIL A SLUG IS TRANSLATED, NOTHING HAPPENS. The backfill seeded every
 * locale's slug with the English one, so both locales name the same string and
 * the comparison below is equal. This middleware only starts doing anything
 * the moment somebody types an Arabic slug — which is exactly when it is
 * needed and not before.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * WHY A MIDDLEWARE AND NOT FOUR CONTROLLERS
 *
 * Projects, articles, services and flat pages all bind a model by slug, and so
 * will anything added later. Four copies of this check is three chances to
 * omit it, and the omission is invisible: the page renders, it just renders at
 * two addresses. One middleware on the route group covers every bound model
 * including the ones that do not exist yet.
 */
class EnsureCanonicalSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only safe methods. Redirecting a POST would discard the body.
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        foreach ($route->parameters() as $name => $value) {
            if (! $value instanceof Model || ! in_array(Translatable::class, class_uses_recursive($value), true)) {
                continue;
            }

            $field = $route->bindingFieldFor($name) ?? $value->getRouteKeyName();

            if (! $value->isTranslatableAttribute($field)) {
                continue;
            }

            $requested = (string) $route->originalParameter($name);
            $canonical = $value->getTranslation($field, Locale::current(), fallback: false);

            /*
             * No slug in this locale at all should be impossible — the
             * generated column is NOT NULL — but if it ever were, serving the
             * page is a better failure than redirecting to nothing.
             */
            if ($canonical === null || $canonical === $requested) {
                continue;
            }

            $target = $this->rebuild($request, $requested, $canonical);

            if ($target === '') {
                continue;   // nothing to correct; see rebuild()
            }

            return redirect()->to($target, 301);
        }

        return $next($request);
    }

    /**
     * Swap one path segment, keeping everything else — including the query
     * string, so a campaign parameter survives the correction.
     */
    private function rebuild(Request $request, string $from, string $to): string
    {
        /*
         * COMPARED DECODED, REBUILT ENCODED.
         *
         * getPathInfo() returns percent-encoded segments while the route
         * parameter is already decoded, so an Arabic slug compared raw never
         * matched — and the "corrected" path came back byte-identical to the
         * one requested, which is a 301 to itself and an infinite loop in the
         * browser. Found by requesting an Arabic slug under the English prefix.
         */
        $segments = array_map(
            static fn (string $segment): string => rawurldecode($segment) === $from
                ? rawurlencode($to)
                : $segment,
            explode('/', trim($request->getPathInfo(), '/'))
        );

        $path = '/'.implode('/', $segments);

        /*
         * A correction that corrects nothing is a loop. Belt to the braces
         * above: if the rebuilt path is the one we were asked for, serve the
         * page rather than bouncing the reader.
         */
        if (rawurldecode($path) === rawurldecode($request->getPathInfo())) {
            return '';
        }
        $query = $request->getQueryString();

        return $query !== null && $query !== '' ? $path.'?'.$query : $path;
    }
}
