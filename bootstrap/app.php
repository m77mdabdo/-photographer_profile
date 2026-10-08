<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleRedirects;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ResolveTheme;
use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        //
    })->create();
