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
