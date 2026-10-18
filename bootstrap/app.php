<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\HandleRedirects;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ResolveTheme;
use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        /*
         * TASK 36 A6 — NO health: HERE. The framework's health route serves
         * its own "Laravel" page, which loads a font and a script from two
         * third-party CDNs and cannot be overridden because the view is read
         * by absolute path. /up is defined in routes/web.php instead, as
         * eleven bytes of text/plain, and the maintenance exemption the
         * health: argument used to register is restored below.
         */
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * REDIRECTS ARE GLOBAL, NOT PART OF THE WEB GROUP.
         *
         * This started as $middleware->web(prepend: ...) and was wrong in a way
         * that took a test to find: in Laravel a middleware GROUP is attached
         * to a route, so it never runs for a request that matches no route —
         * which is precisely the request a redirect exists to serve.
         *
         * It appeared to work only because most of the paths under test
         * happened to match the flat-page wildcard. A path that matched
         * nothing — "/Old-Name", or any address with a slash in it — 404'd
         * without the middleware being consulted at all.
         *
         * Global middleware runs before routing, which is where this belongs.
         * It costs no query on a miss (see the class), so running it on every
         * request is free.
         */
        /*
         * TASK 35 A6 — COMPRESSION, OUTSIDE EVERYTHING THAT RENDERS.
         *
         * This said "OUTERMOST" and that was no longer true by the time it
         * was written: prepend() puts a middleware at the FRONT of the stack,
         * so the last prepend in this closure ends up first, and SetLocale is
         * prepended below. The order the kernel actually reports is
         * SecurityHeaders, SetLocale, HandleRedirects, CompressResponse.
         *
         * What the claim needed to be is what matters and is still true: it
         * is outside every middleware that can render a body, so it sees the
         * finished bytes of every response the application can produce —
         * including the ones that never reach a route, the 404 and the 419.
         *
         * It does nothing when a web server has already encoded the response.
         * See the class.
         */
        /*
         * TASK 36 A6 — /up STILL ANSWERS WHILE THE SITE IS DOWN FOR
         * MAINTENANCE, which is the whole point of an uptime check.
         * withRouting(health:) used to register this exemption; defining the
         * route by hand in routes/web.php means registering it by hand.
         */
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        $middleware->prepend(CompressResponse::class);

        $middleware->prepend(HandleRedirects::class);

        /*
         * THE LOCALE IS GLOBAL FOR THE SAME REASON REDIRECTS ARE.
         *
         * A request that matches no route still renders a page — the 404, the
         * 419 — and that page still has a writing direction. Group middleware
         * never runs for it, so an Arabic 404 came out left-to-right. Running
         * before routing fixes it for every response the application can
         * produce, including the ones the router never saw.
         */
        $middleware->prepend(SetLocale::class);

        /*
         * ───────────────────────────────────────────────────────────────────
         * TASK 36 C — THE SECURITY HEADERS, GENUINELY OUTERMOST, WHICH MEANS
         * PREPENDED LAST.
         *
         * prepend() puts a middleware at the front, so the LAST call wins the
         * outside position. This started life beside CompressResponse above,
         * under a comment claiming it was "outermost of all" — and the kernel
         * reported it fourth of four, inside SetLocale and HandleRedirects.
         * The headers were still on every response that was MEASURED, because
         * none of those three short-circuits on the paths that were tried;
         * but a response produced by HandleRedirects' own early return would
         * have carried none of them.
         *
         * Outermost gives two things. Every response gets the headers,
         * including one a middleware returns without ever reaching a view.
         * And the CSP nonce is bound before anything downstream can render,
         * which is the half SetLocale and HandleRedirects do not care about
         * but every Blade template does.
         * ───────────────────────────────────────────────────────────────────
         */
        $middleware->prepend(SecurityHeaders::class);

        /*
         * THE LOCALE COOKIE IS NOT ENCRYPTED, AND IT HAS TO BE.
         *
         * SetLocale runs before routing, which puts it ahead of EncryptCookies
         * in the pipeline — so it sees the raw cookie jar. An encrypted value
         * read there is ciphertext, and the stored preference silently stopped
         * working while every page still returned 200. Two tests caught it.
         *
         * Excepting it is the right answer rather than a workaround: the value
         * is a two-letter language tag with no security meaning, it is already
         * served non-httpOnly for the same reason, and it is validated against
         * the supported set on the way in — so a hand-edited cookie can only
         * ever select a locale that exists.
         */
        $middleware->encryptCookies(except: [Locale::COOKIE]);

        // Every web response needs the resolved theme before it renders.
        $middleware->web(append: [ResolveTheme::class]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'twofactor' => EnsureTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * ═══════════════════════════════════════════════════════════════════
         * TASK 36 A5 — THREE MORE UNAUTHENTICATED ROUTES THAT ANSWERED 500.
         *
         * Livewire publishes a per-component asset route whether or not any
         * component has assets:
         *
         *     GET /livewire-<hash>/js/{component}.js
         *     GET /livewire-<hash>/css/{component}.css
         *     GET /livewire-<hash>/css/{component}.global.css
         *
         * Asked for a component that does not exist, Livewire throws
         * ComponentNotFoundException, and the audit's walk of every GET route
         * as a guest got three 500s and three exceptions in the log. Same
         * shape as the filament/actions download routes: unauthenticated,
         * unthrottled, one log line per request, and a free way to confirm
         * which framework is underneath.
         *
         * A REQUEST FOR A COMPONENT THAT DOES NOT EXIST IS A 404, which is
         * what every other address this application does not serve returns.
         *
         * SCOPED TO THOSE ROUTES, DELIBERATELY. The same exception is thrown
         * when a Blade template names a component that is misspelled, and
         * turning THAT into a 404 would hide a developer's typo behind a
         * missing page. So the rewrite only applies where the component name
         * came from the URL, and a typo inside a view stays the loud 500 it
         * should be.
         * ═══════════════════════════════════════════════════════════════════
         */
        /*
         * The prefix is asked of Livewire rather than written down: it is a
         * hash of APP_KEY (EndpointResolver::prefix), so it differs per
         * installation and a literal here would be wrong on the first deploy.
         */
        $isComponentAssetRequest = function (?Request $request): bool {
            if ($request === null) {
                return false;
            }

            $path = rtrim($request->getPathInfo(), '/');
            $prefix = EndpointResolver::prefix();

            foreach (['/js/', '/css/'] as $segment) {
                if (str_starts_with($path, $prefix.$segment)) {
                    return true;
                }
            }

            return false;
        };

        $exceptions->render(function (ComponentNotFoundException $e, Request $request) use ($isComponentAssetRequest) {
            return $isComponentAssetRequest($request) ? response()->noContent(404) : null;
        });

        /*
         * And it is not worth a line in the log either, for the same reason a
         * 404 is not: the only thing it records is that somebody guessed.
         */
        $exceptions->dontReportWhen(fn (\Throwable $e): bool => $e instanceof ComponentNotFoundException
            && $isComponentAssetRequest(request()));
    })->create();
