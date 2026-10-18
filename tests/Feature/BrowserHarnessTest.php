<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The harness itself is a deliverable, so it is checked like one.
 *
 * None of this runs a browser — that is the point of the harness being a
 * separate command. What these assert is that the command exists, is wired up,
 * is runnable, and cannot leak a development dependency into the public
 * bundle.
 */
class BrowserHarnessTest extends TestCase
{
    public function test_the_harness_is_committed_and_wired_up(): void
    {
        $script = base_path('bin/browser-check.mjs');

        $this->assertFileExists($script, 'the browser harness is missing');

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertIsArray($package);
        $this->assertSame(
            'node bin/browser-check.mjs',
            $package['scripts']['check:browser'] ?? null,
            'one command must run it'
        );

        // Documented, with the limits written down.
        $docs = base_path('docs/operations/browser-checks.md');

        $this->assertFileExists($docs);

        $contents = (string) file_get_contents($docs);

        $this->assertStringContainsString('npm run check:browser', $contents);
        $this->assertStringContainsString('What it still cannot see', $contents);
    }

    public function test_the_harness_is_syntactically_valid_and_runnable(): void
    {
        /*
         * `node --check` parses the file without executing it. It is the
         * closest thing to `php -l` for an ES module, and it is what stops a
         * broken harness being committed as "the tests passed".
         */
        $process = new \Symfony\Component\Process\Process(
            ['node', '--check', 'bin/browser-check.mjs'],
            base_path(),
        );

        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'the harness does not parse: '.$process->getErrorOutput()
        );

        // And it declines gracefully rather than hanging when told to check a
        // host that is not there.
        $help = new \Symfony\Component\Process\Process(
            ['node', 'bin/browser-check.mjs', '--base=http://127.0.0.1:59999', '--only=/nothing'],
            base_path(),
            null,
            null,
            120,
        );

        $help->run();

        $this->assertContains(
            $help->getExitCode(),
            [0, 1, 2],
            'the harness exited unexpectedly: '.$help->getErrorOutput()
        );
    }

    /**
     * A dev tool that reaches the public bundle is not a dev tool.
     */
    public function test_puppeteer_is_a_dev_dependency_and_never_shipped(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertIsArray($package);

        $this->assertArrayHasKey(
            'puppeteer-core',
            $package['devDependencies'] ?? [],
            'puppeteer-core must be a devDependency'
        );

        $this->assertArrayNotHasKey(
            'puppeteer-core',
            $package['dependencies'] ?? [],
            'puppeteer-core must never be a runtime dependency'
        );

        // Nothing under resources/ imports it, so Vite cannot bundle it.
        foreach ((glob(resource_path('js/*.js')) ?: []) + (glob(resource_path('js/**/*.js')) ?: []) as $file) {
            $this->assertStringNotContainsString(
                'puppeteer',
                (string) file_get_contents($file),
                basename($file).' imports the browser harness into the bundle'
            );
        }

        // And the built public bundle is still free of it.
        foreach (glob(public_path('build/assets/public-*.js')) ?: [] as $bundle) {
            $this->assertStringNotContainsString('puppeteer', (string) file_get_contents($bundle));
        }
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36b — THE TARGET LIST, VERIFIED AGAINST THE ROUTER.
     *
     * The harness's paths were read off `php artisan route:list` by hand once
     * — the rule G3 set after eighteen targets were found pointing at an
     * admin that no longer existed — and a list checked by hand once is a
     * list that was correct once. This checks it on every run, in BOTH
     * directions:
     *
     *   a page with no target   — a public page nobody is looking at
     *   a target with no route  — a row auditing a 404 under a page's name
     *
     * "A PAGE" IS DECIDED BY ASKING, not by a list of exemptions. Every
     * parameterless public GET route is requested as a guest, and only the
     * ones that answer 200 with HTML are required to have a target. That
     * drops the redirects (/legacy/*, /en/*), the non-pages (/up, robots.txt)
     * and anything behind the login without naming any of them, so the rule
     * cannot rot as those change.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_every_public_page_has_a_harness_target(): void
    {
        $targets = $this->harnessTargets();

        $this->assertNotEmpty($targets, 'no targets could be parsed out of the harness');

        $missing = [];
        $checked = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = '/'.trim($route->uri(), '/');

            if (str_contains($uri, '{')) {
                continue;
            }

            $response = $this->get($uri);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                continue;
            }

            $checked++;

            /*
             * A LOCALE-PREFIXED PAGE IS COVERED BY ITS UNPREFIXED TWIN. The
             * harness does not list /ar/work beside /work; it takes
             * --locale=ar and prefixes every target itself, which is how the
             * matrix gets eight runs out of one list. So the prefix comes off
             * before comparing — and Locale::pathFor is the same function the
             * harness's own localised() mirrors.
             */
            $unprefixed = preg_replace('#^/(ar|en)(?=/|$)#', '', $uri) ?: '/';

            if (! in_array($uri, array_column($targets, 'path'), true)
                && ! in_array($unprefixed, array_column($targets, 'path'), true)) {
                $missing[] = $uri;
            }
        }

        $this->assertGreaterThan(
            5,
            $checked,
            'almost nothing answered 200 as a guest, so this test is not looking at the site'
        );

        $this->assertSame(
            [],
            $missing,
            "public pages with no browser-check target:\n  ".implode("\n  ", $missing)
        );
    }

    /** And no target pointing at an address the router does not serve. */
    public function test_no_harness_target_points_at_a_missing_route(): void
    {
        $routes = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $routes[] = '/'.trim($route->uri(), '/');
        }

        $unmatched = [];

        foreach ($this->harnessTargets() as $target) {
            /*
             * A target the harness itself marks optional is auditing
             * something that has no route on purpose — /_errors/419 renders
             * the expired-token page, and declares status 404 to say so.
             */
            if ($target['optional']) {
                continue;
            }

            if (in_array($target['path'], $routes, true)) {
                continue;
            }

            /*
             * A target that names a record — /work/salt-line — matches the
             * parameterised route it belongs to. Compared segment by segment
             * so /archive never matches /ar.
             */
            if (! $this->matchesAnyRoute($target['path'], $routes)) {
                $unmatched[] = $target['path'];
            }
        }

        $this->assertSame(
            [],
            $unmatched,
            "harness targets with no matching route — each audits a 404 under a page's name:\n  "
            .implode("\n  ", $unmatched)
        );
    }

    /**
     * The harness's target list, as [path, optional].
     *
     * @return list<array{path: string, optional: bool}>
     */
    private function harnessTargets(): array
    {
        $source = (string) file_get_contents(base_path('bin/browser-check.mjs'));

        /*
         * Each target is an object literal. The path and the optional flag are
         * read from the SAME literal rather than from two independent scans,
         * or an optional flag would be attributed to the wrong path.
         */
        preg_match_all('/\{[^{}]*?path:\s*\x27([^\x27]+)\x27[^{}]*?\}/s', $source, $matches, PREG_SET_ORDER);

        $targets = [];

        foreach ($matches as $match) {
            $targets[] = [
                'path' => '/'.trim(explode('?', $match[1])[0], '/'),
                'optional' => str_contains($match[0], 'optional: true'),
            ];
        }

        return $targets;
    }

    /** @param list<string> $routes */
    private function matchesAnyRoute(string $path, array $routes): bool
    {
        $segments = explode('/', trim($path, '/'));

        foreach ($routes as $route) {
            $routeSegments = explode('/', trim($route, '/'));

            if (count($routeSegments) !== count($segments)) {
                continue;
            }

            $fits = true;

            foreach ($routeSegments as $index => $segment) {
                if (str_starts_with($segment, '{')) {
                    continue;
                }

                if ($segment !== $segments[$index]) {
                    $fits = false;

                    break;
                }
            }

            if ($fits) {
                return true;
            }
        }

        return false;
    }

    public function test_the_harness_checks_the_things_it_claims_to(): void
    {
        $source = (string) file_get_contents(base_path('bin/browser-check.mjs'));

        /*
         * The specific checks, asserted by name. A harness that quietly stops
         * looking at images is worse than no harness, because the suite still
         * goes green and somebody now believes a browser looked.
         */
        foreach ([
            'naturalWidth' => 'images actually decoding',
            'scrollWidth' => 'horizontal overflow',
            'document.fonts.check' => 'fonts applying rather than being declared',
            'getBoundingClientRect' => 'hero geometry',
            'requestfailed' => 'failed network requests',
            "msg.type()" => 'console errors',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $source, "the harness no longer checks {$what}");
        }

        // Every viewport and both themes.
        foreach (['1440', '1024', '768', '390'] as $viewport) {
            $this->assertStringContainsString("label: '{$viewport}'", $source, "viewport {$viewport} is not covered");
        }

        foreach (["scheme: 'dark'", "scheme: 'light'"] as $theme) {
            $this->assertStringContainsString($theme, $source);
        }

        // And the pages that had never been opened in a browser.
        foreach (['/work', '/archive', '/work/category/', '/work?page=2'] as $path) {
            $this->assertStringContainsString($path, $source, "{$path} is not in the page list");
        }
    }
}
