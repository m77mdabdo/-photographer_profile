<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PageTransition;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 C — THE HEADERS THIS SITE WAS SERVING: NONE.
 *
 * Measured against a production build before anything was written here:
 *
 *     HTTP/1.1 200 OK
 *     X-Powered-By: PHP/8.5.2
 *     Content-Type: text/html; charset=utf-8
 *     Cache-Control: no-cache, private
 *     Vary: Cookie, Accept-Encoding
 *
 * No Content-Security-Policy, no X-Content-Type-Options, no Referrer-Policy,
 * no Permissions-Policy, nothing about framing, and the PHP version
 * announced to anybody who asks. Every one of those is a header the browser
 * will enforce for free and none of them was being sent.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE CSP IS NOT THE SAME ON BOTH SURFACES, AND SAYING SO IS THE POINT.
 *
 * C2 asks for nonces and for honesty where a strict policy is not reachable.
 * This application has two front ends with different needs:
 *
 *   THE PUBLIC SITE — strict, and genuinely so:
 *
 *       script-src 'self' 'nonce-…'
 *
 *     Every script is a Vite module from this origin, carrying the nonce.
 *     (The one inline boot block, components/motion-boot.blade.php, went
 *     with the motion registry in Task 42.) No 'unsafe-inline', no 'unsafe-eval', no host allow-list.
 *     Laravel's Vite helper stamps the nonce onto its own tags once
 *     Vite::useCspNonce() has been called, which is what the line below in
 *     handle() does. JSON-LD blocks are data, not script, and browsers do
 *     not apply script-src to them.
 *
 *   THE PANEL — strict about everything except eval, and that is Alpine:
 *
 *       script-src 'self' 'nonce-…' 'unsafe-eval'
 *
 *     Filament is Alpine, and Alpine evaluates the expressions written in
 *     x-data, x-show and x-on attributes with `new Function`. A policy
 *     without 'unsafe-eval' stops the panel booting: the sidebar never
 *     opens, no modal renders, no table action fires. Alpine publishes a CSP
 *     build that pre-compiles expressions, and Filament does not ship it;
 *     adopting it would mean vendoring a different Alpine into a panel whose
 *     every component writes expressions the CSP build cannot evaluate.
 *
 *     So the panel's policy is weaker than the public site's, and the honest
 *     description is: it blocks injected <script src> and injected inline
 *     script, it does not block a payload that reaches an Alpine expression.
 *     It is behind a login, behind two-factor, behind a role check, and it
 *     renders no third-party content. That is the trade, written down rather
 *     than hidden behind a policy that permits everything.
 *
 * STYLE ATTRIBUTES ARE ALLOWED, AND SCRIPTS ARE NOT. The hero sizes itself
 * with style="--wordmark-chars: 11" and Livewire writes style attributes as
 * it updates the DOM, so style-src-attr carries 'unsafe-inline'. A style
 * attribute cannot execute; the dangerous half of 'unsafe-inline' is the
 * script half, and that is the half this policy refuses.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * AND THE PANEL'S INLINE <style> BLOCKS, WHICH ARE WHERE STRICT RAN OUT.
 *
 * The first version of this policy sent style-src 'self' to both surfaces.
 * Chrome, driven with a securitypolicyviolation listener installed before any
 * page script ran, reported it exactly:
 *
 *     public site, 12 pages x 2 locales ...  0 violations
 *     panel, 7 screens x 2 locales ........ 84 violations
 *     of those, script-src ................  0
 *     of those, style-src-elem ............ 84
 *
 * Every one is an inline <style> ELEMENT, from four places, none of them ours:
 *
 *   vendor/filament/filament/.../layout/base.blade.php:49   the x-cloak rules
 *   vendor/filament/filament/.../layout/base.blade.php:83   the CSS variables
 *   vendor/filament/support/.../assets.blade.php:21         more CSS variables
 *   livewire.js:14089                                       the progress bar,
 *                                                           injected at runtime
 *
 * Filament nonces its inline SCRIPT in that same assets view — it already
 * reads Vite::cspNonce() — and nonces none of its styles. Three of the four
 * could be nonced by publishing those vendor views and editing them, which
 * means owning Filament's layout through every future upgrade in exchange for
 * a style directive; the fourth is injected by Livewire's own JavaScript and
 * could not be nonced at all. Hashes do not help either: two of the four
 * interpolate the theme's colours and font names, so they change with the
 * settings record.
 *
 * SO THE PANEL GETS style-src 'self' 'unsafe-inline' AND THE PUBLIC SITE
 * DOES NOT. What that permits is injected CSS on an authenticated screen:
 * restyling, and the exfiltration-by-selector tricks that need an attacker
 * already able to inject markup. What it does not permit is execution —
 * script-src is unchanged, and the panel measured zero script violations.
 *
 * THE FRONT DOOR IS NOT THE PANEL. /login, /forgot-password, /reset-password
 * and /two-factor-challenge are this project's own Blade and load no script
 * at all — `curl /login | grep '<script'` returns nothing — so they take the
 * strict policy with the rest of the public site. That is why isPanel() below
 * asks about the PANEL PATH and not about Locale::isPrivatePath(), which the
 * first version of this class used and which would have handed the login page
 * 'unsafe-eval' for no reason.
 *
 * Every claim in this comment has an assertion in
 * tests/Feature/Security/SecurityHeadersTest.php, and the one claim PHPUnit
 * cannot make — that the pages still WORK under the policy — is made by
 * bin/browser-check.mjs, which now listens for securitypolicyviolation on
 * every target it visits.
 * ───────────────────────────────────────────────────────────────────────────
 * ═══════════════════════════════════════════════════════════════════════════
 */
class SecurityHeaders
{
    /** Where the nonce lives for this request. */
    public const NONCE = 'csp.nonce';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);

        app()->instance(self::NONCE, $nonce);

        /*
         * Laravel's Vite helper reads this when it writes its <script> and
         * <link> tags, and Filament's own assets view reads the same value —
         * so one call covers both front ends' generated markup.
         */
        Vite::useCspNonce($nonce);

        $response = $next($request);

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $this->minimalHeaders($response, $request);
        }

        $this->minimalHeaders($response, $request);

        /*
         * A policy is only meaningful on a document. Applying one to a JSON
         * response or an image costs a header and protects nothing.
         */
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', $this->policy($request, $nonce));
        }

        return $response;
    }

    /** The headers every response gets, whatever its body is. */
    private function minimalHeaders(Response $response, Request $request): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        /*
         * Nothing on this site asks for a camera, a microphone, a location or
         * a payment handler. Saying so turns a future dependency that tries
         * into a console error rather than a prompt in front of a visitor.
         */
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        );

        /*
         * HSTS ONLY OVER HTTPS. Sent on a plain-HTTP response it is ignored
         * by browsers and misleading to everybody else; sent from a
         * development server on http://127.0.0.1 it would pin the developer's
         * own machine to HTTPS for a year.
         */
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        /*
         * PHP announces its exact version by default, which is free
         * reconnaissance for anybody scanning for a version with a known
         * hole. The ini setting is expose_php=Off; this is the half the
         * application controls.
         */
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        return $response;
    }

    /** The policy for this request's surface. */
    private function policy(Request $request, string $nonce): string
    {
        $isPanel = $this->isPanel($request);

        $script = "'self' 'nonce-{$nonce}'".($isPanel ? " 'unsafe-eval'" : '');

        /*
         * NO NONCE IN style-src. A nonce or a hash in the same directive makes
         * a browser IGNORE 'unsafe-inline' — so adding one here would silently
         * re-break every panel screen.
         */
        // The public surface allows one inline style by hash: the page
        // transition's opt-in (App\Support\PageTransition says why inline).
        $style = $isPanel ? "'self' 'unsafe-inline'" : "'self' ".PageTransition::hash();

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self'",
            "manifest-src 'self'",
            'upgrade-insecure-requests',
        ]);
    }

    /**
     * Is this request for a screen Filament renders?
     *
     * Asked of the PATH rather than of the route, because this runs before
     * routing — the same reason SetLocale and HandleRedirects do — and a
     * request that matches no route still gets a policy.
     *
     * The prefix comes from the panel rather than from a literal, so that
     * moving the panel off /admin moves its policy with it. Filament's
     * registry is available this early; getPath() is configuration, and
     * reading it starts nothing.
     */
    private function isPanel(Request $request): bool
    {
        $path = trim($request->getPathInfo(), '/');

        foreach (Filament::getPanels() as $panel) {
            $prefix = trim($panel->getPath(), '/');

            if ($prefix === '') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        /*
         * Livewire's own endpoints — the update POST and the temporary upload
         * — are not under the panel path and are how every panel interaction
         * actually travels. They answer JSON, so no policy is attached to
         * them, but a redirect or an error page from one would be HTML.
         */
        return str_starts_with($path, 'livewire/');
    }
}
