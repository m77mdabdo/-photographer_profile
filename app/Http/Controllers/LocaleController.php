<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch locale, remember the choice, and land on the same page.
     *
     * A plain form POST with a redirect, exactly like the theme switcher, so
     * it works with scripting disabled — which on this site is not a
     * concession but the baseline.
     *
     * WHY A POST AND NOT A LINK
     *
     * Because this writes a cookie, and a cookie is a preference. A GET link
     * that set one would store a preference for every crawler that followed
     * the hreflang alternates, and for anyone who arrived on an Arabic page
     * from a search result without having chosen anything. The rule stated in
     * the middleware — no cookie without a choice — is only true if the
     * choice is an action.
     *
     * Crawlers still discover both locales: that is what the hreflang
     * alternates in <head> are for, and they are plain <link> elements with
     * real URLs.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', Locale::codes())],
            'return_to' => ['nullable', 'string'],
        ]);

        $locale = (string) $validated['locale'];

        /*
         * The destination is the SAME PAGE in the other locale, which is the
         * whole point: switching language on a project must land on that
         * project. Locale::pathFor strips whatever prefix the submitted path
         * carries and applies the new one, so a round trip
         * /ar/work/x -> /work/x -> /ar/work/x is stable.
         *
         * ───────────────────────────────────────────────────────────────────
         * EXCEPT BEHIND THE LOGIN, WHERE THE ADDRESS DOES NOT CHANGE.
         *
         * Task 34b. /admin/projects is the same address in both languages —
         * see Locale::PRIVATE_PREFIXES — so prefixing a panel path would send
         * the editor to /ar/admin/projects, which is a door that writes the
         * cookie and redirects back. The cookie below is already doing that
         * job, so the prefix would buy a second round trip and nothing else.
         *
         * The query string survives for the same surface and only there. On
         * the public site a ?page=4 carried across a language change lands on
         * a pagination state that may not exist; in the panel the query IS
         * the state — the dashboard's deep links carry tableFilters, and
         * switching language on a filtered list must not quietly unfilter it.
         * ───────────────────────────────────────────────────────────────────
         */
        $return = $this->safeReturnPath($request);

        $target = Locale::isPrivatePath($return)
            ? $return
            : Locale::pathFor($locale, (string) (parse_url($return, PHP_URL_PATH) ?: '/'));

        return redirect()->to($target)->withCookie(cookie(
            name: Locale::COOKIE,
            value: $locale,
            minutes: Locale::COOKIE_DAYS * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),

            /*
             * Not httpOnly, for the same reason as the theme cookie: a
             * language preference has no security meaning and leaving it
             * readable costs nothing.
             */
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * Only ever a path within this application.
     *
     * A redirect target taken from a form field is an open redirect, and
     * `//evil.example` is a protocol-relative URL that `str_starts_with('/')`
     * alone would wave through. Both checks are needed.
     */
    private function safeReturnPath(Request $request): string
    {
        $candidate = (string) $request->input('return_to', '');

        if ($candidate === '' || ! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return '/';
        }

        /*
         * The QUERY STRING IS KEPT HERE and dropped by the caller for the
         * public site, rather than thrown away in this method as it was until
         * Task 34b. The panel needs it — see the caller — and a method called
         * "safe" should be about safety, not about which half of a URL a
         * particular surface wants.
         */
        $path = (string) (parse_url($candidate, PHP_URL_PATH) ?: '/');
        $query = parse_url($candidate, PHP_URL_QUERY);

        return $query === null || $query === '' ? $path : $path.'?'.$query;
    }
}
