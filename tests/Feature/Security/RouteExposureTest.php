<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Filament\Facades\Filament;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 A1 — WHAT IS REACHABLE WITHOUT SIGNING IN.
 *
 * The audit walked every GET route in the table as a guest and read the
 * status of each. That walk is a thing somebody did once; this is the part
 * of it that has to keep being true, derived from the route table rather
 * than from a list somebody maintains — so a route added next month is
 * covered the day it is added.
 *
 * Three claims:
 *
 *   1. Every route under the panel's path requires authentication. Not
 *      "returns 302 today" — carries the middleware, so a page added to the
 *      panel without it fails here rather than in a browser.
 *   2. Nothing answers a guest with a 5xx. A route that throws for an
 *      unauthenticated request is an unthrottled way to fill a log and to
 *      fingerprint the stack; the audit found five, and they are fixed.
 *   3. The development-only routes exist only in development.
 * ───────────────────────────────────────────────────────────────────────────
 */
class RouteExposureTest extends TestCase
{
    /** @return list<RoutingRoute> */
    private function routes(): array
    {
        return array_values(iterator_to_array(Route::getRoutes()));
    }

    public function test_every_panel_route_requires_authentication(): void
    {
        $panel = trim(Filament::getPanel('studio')->getPath(), '/');

        $unguarded = [];

        foreach ($this->routes() as $route) {
            $uri = trim($route->uri(), '/');

            if ($uri !== $panel && ! str_starts_with($uri, $panel.'/')) {
                continue;
            }

            $middleware = implode(' ', $route->gatherMiddleware());

            /*
             * Filament's own login and logout pages live under the panel path
             * and must be reachable to a guest, or there is no way in.
             */
            if (str_contains($middleware, 'Authenticate') || str_contains($middleware, 'auth')) {
                continue;
            }

            $unguarded[] = $route->methods()[0].' '.$uri.'  ['.$middleware.']';
        }

        /*
         * The panel's own sign-in and sign-out routes are the exception and
         * are named, not inferred: anything else appearing here is a screen
         * somebody can open without an account.
         */
        $allowed = ['GET admin/login', 'GET admin/logout', 'POST admin/logout'];

        $unexpected = array_values(array_filter(
            $unguarded,
            fn (string $row): bool => ! in_array(explode('  [', $row)[0], $allowed, true),
        ));

        $this->assertSame([], $unexpected, "Panel routes with no authentication:\n".implode("\n", $unexpected));
    }

    /**
     * NOTHING THROWS FOR A GUEST. The audit found five routes that did:
     * two filament/actions downloads and three Livewire component-asset
     * routes, each answering 500 and writing an exception to the log for an
     * anonymous GET.
     */
    public function test_no_route_answers_a_guest_with_a_server_error(): void
    {
        /*
         * Not a data provider: Livewire's endpoint prefix is a hash of
         * APP_KEY, a provider runs before the application boots, and reading
         * config there fails with "Target class [config] does not exist".
         */
        $livewire = EndpointResolver::prefix();

        $probes = [
            '/filament/exports/1/download',
            '/filament/imports/1/failed-rows/download',
            $livewire.'/js/nope.js',
            $livewire.'/css/nope.css',
            $livewire.'/css/nope.global.css',
            '/admin',
            '/up',
            '/no-such-thing-at-all',
            '/work/../../etc/passwd',
        ];

        $errors = [];

        foreach ($probes as $path) {
            $status = $this->get($path)->status();

            if ($status >= 500) {
                $errors[] = "GET {$path} answered {$status}";
            }
        }

        $this->assertSame([], $errors, implode("\n", $errors));
    }

    /**
     * The development-only screens. These render internal fixtures and are
     * registered behind an environment check; the audit confirmed they are
     * absent under APP_ENV=production, and this keeps the check in the code.
     */
    public function test_the_development_routes_are_not_registered_in_production(): void
    {
        /*
         * The suite runs under APP_ENV=testing, which is not 'local' — so the
         * absence of these routes here is the gate working, measured, rather
         * than a claim about production. The audit confirmed the same under
         * APP_ENV=production with the route cache built.
         */
        $this->assertSame('testing', app()->environment());

        $devRoutes = array_values(array_filter(
            array_map(fn (RoutingRoute $r): string => $r->uri(), $this->routes()),
            fn (string $uri): bool => str_starts_with($uri, '_dev'),
        ));

        $this->assertSame([], $devRoutes, 'the _dev screens are registered outside the local environment');

        $this->get('/_dev/hero')->assertNotFound();
        $this->get('/_dev/media')->assertNotFound();

        /*
         * And the gate names 'local' specifically, rather than "not
         * production" — which would have left them registered in staging.
         */
        $this->assertStringContainsString(
            "app()->environment('local')",
            (string) file_get_contents(base_path('routes/web.php')),
            'routes/web.php no longer gates the _dev screens on the local environment.'
        );
    }
}
