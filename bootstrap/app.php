<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ResolveTheme;
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
