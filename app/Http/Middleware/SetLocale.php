<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locale;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the active locale for a request, and decides what the bare root does.
 *
 * THE ORDER OF PRECEDENCE
 *
 *   1. the URL                — decided before this runs, by which route
 *                               group matched. `/ar/work` is Arabic because
 *                               it is the Arabic route, not because of a
 *                               negotiation.
 *   2. a stored preference    — the cookie the switcher writes, and only the
 *                               switcher writes it.
 *   3. Accept-Language        — the browser's own setting.
 *   4. the default            — English.
 *
 * Steps 2 to 4 are consulted for EXACTLY ONE ADDRESS: the bare root. Every
 * other address states its own locale and is served in it, with no
 * negotiation and no redirect.
 *
 * WHY NEGOTIATION IS CONFINED TO ONE ADDRESS
 *
 * Because an address that returns different content to different clients is an
 * address that cannot be canonical, cannot be cached by a CDN without a Vary
 * header on a high-cardinality field, and cannot be verified by looking at it.
 * Google's own guidance is against redirecting on perceived language, for a
 * concrete reason: a crawler arrives with `Accept-Language: en` from a US
 * address, so a site that redirects on that header shows the crawler English
 * every time and the Arabic pages are never seen at the address that links to
 * them. hreflang then describes a reciprocity that does not exist.
 *
 * The root is the exception because it is the one address with no content of
 * its own to be canonical about, and because it is where a returning visitor
 * types the domain and expects their language. That redirect is a 302, never a
 * 301: the destination depends on who is asking, so it must not be cached by
 * anything.
 */
class SetLocale
{
    /**
     * @param  string|null  $locale  the locale this route group serves, or
     *                               null to read it from the request path
     */
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        /*
         * REGISTERED GLOBALLY, NOT ON THE ROUTE GROUP, AND THE REASON IS 404s.
         *
         * This started as group middleware with the locale passed in, which
         * reads well at the route definition and is wrong for exactly the
         * requests that match no route. A 404 under /ar never enters a route
         * group, so the locale was never set, so the error page rendered
         * `dir="ltr"` with an English layout at an Arabic address. The browser
         * harness found it on /ar/no-such-page at all eight viewports.
         *
         * Global middleware runs before routing, which is where this belongs —
         * the same argument, and the same mistake, as HandleRedirects.
         *
         * With no parameter the locale comes from the path, which is what
         * "the URL segment wins" means operationally. The parameter is kept so
         * a route group can still state its locale explicitly if one ever
         * needs to.
         */
        $locale ??= Locale::split($request->getPathInfo())[0] ?? Locale::DEFAULT;

        $locale = Locale::supports($locale) ? $locale : Locale::DEFAULT;

        App::setLocale($locale);

        /*
         * ─────────────────────────────────────────────────────────────────
         * RE-OVERLAY THE CONFIG, NOW THAT THE LOCALE IS KNOWN.
         *
         * Settings::overlayConfig() lays the settings table over config so
         * that config('seo.site_name') and config('seo.default_description')
         * carry the studio's own words. It runs in AppServiceProvider::boot(),
         * which is BEFORE any middleware — so it read the settings in the
         * DEFAULT locale and froze the English there for the rest of the
         * request.
         *
         * The consequence was not subtle: the header wordmark, the hero
         * wordmark, og:site_name and — worst — every Arabic page's <title> and
         * meta description were English. An Arabic page was telling search
         * engines it was an English page about an English-named studio.
         *
         * Called again here, after the locale is settled, so the overlay
         * reflects the page actually being rendered. It is one cached map and
         * a handful of config() writes, so the cost is nil; the boot-time call
         * stays because console commands and queued jobs have no middleware.
         * ─────────────────────────────────────────────────────────────────
         */
        Settings::overlayConfig();

        /*
         * Exposed to views as well as to the app. The layout reads
         * Locale::current() directly, but a view that wants the active locale
         * without reaching for a facade should be able to have it.
         */
        View::share('locale', $locale);
        View::share('localeDirection', Locale::direction($locale));

        /*
         * ─────────────────────────────────────────────────────────────────
         * THE ADMIN SURFACES FOLLOW THE EDITOR'S STORED PREFERENCE.
         *
         * Negotiation is confined to one public address because a public page
         * that varies by header cannot be canonical and cannot be crawled
         * predictably. NONE of that applies behind a login: /studio, /admin
         * and the auth pages are noindex, they have one address each, and no
         * crawler will ever see them.
         *
         * So for those paths the stored preference simply decides, with no
         * redirect and no second URL. An Arabic editor gets an Arabic panel
         * at the same address an English one uses, which is the only
         * arrangement that works for a panel Filament gives a single path.
         * ─────────────────────────────────────────────────────────────────
         */
        if ($this->isPrivateSurface($request)) {
            $preferred = $this->preferred($request);

            App::setLocale($preferred);
            View::share('locale', $preferred);
            View::share('localeDirection', Locale::direction($preferred));

            return $next($request);
        }

        /*
         * The one negotiating address. Only the root, only for the default
         * locale's route group, and only when the visitor would prefer
         * something else.
         */
        if ($this->isBareRoot($request) && Locale::isDefault($locale)) {
            $preferred = $this->preferred($request);

            if (! Locale::isDefault($preferred)) {
                return redirect()->to(Locale::pathFor($preferred, '/'), 302);
            }
        }

        return $next($request);
    }

    /**
     * Paths behind a login, which have one address each and are never indexed.
     *
     * @var list<string>
     */
    private const PRIVATE_PREFIXES = [
        'studio', 'admin', 'login', 'logout', 'register',
        'forgot-password', 'reset-password', 'two-factor-challenge', 'user',
    ];

    private function isPrivateSurface(Request $request): bool
    {
        [, $bare] = Locale::split($request->getPathInfo());

        $first = explode('/', ltrim($bare, '/'))[0] ?? '';

        return in_array($first, self::PRIVATE_PREFIXES, true);
    }

    /**
     * Exactly `/` — not `/about`, and not `/?utm_source=…` either, which is
     * still the root and should still negotiate.
     */
    private function isBareRoot(Request $request): bool
    {
        return $request->getPathInfo() === '/';
    }

    /**
     * The visitor's preference: the cookie, then the browser, then the default.
     *
     * The cookie is read RAW, because this middleware runs before routing and
     * therefore ahead of EncryptCookies — so the jar it sees is the one the
     * browser sent. That only works because the cookie is excepted from
     * encryption in bootstrap/app.php, which is deliberate and documented
     * there. It is validated against the supported set regardless, so a
     * hand-edited cookie cannot steer this anywhere that is not a real
     * locale.
     */
    private function preferred(Request $request): string
    {
        $stored = Locale::fromCookie($request->cookie(Locale::COOKIE));

        if ($stored !== null) {
            return $stored;
        }

        return $request->getPreferredLanguage(Locale::codes()) ?? Locale::DEFAULT;
    }
}
