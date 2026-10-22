<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 G — WHAT THE ADVISORIES CAN AND CANNOT REACH.
 *
 * composer audit: no advisories.
 *
 * npm audit: eight high-severity advisories, seven of which are one
 * dependency chain —
 *
 *     puppeteer-core → @puppeteer/browsers → proxy-agent →
 *     pac-proxy-agent → get-uri → basic-ftp,  and  extract-zip
 *
 * — and the eighth (source-map-js, GHSA-68fv-2mgg-jv7q) was fixed in place,
 * 1.2.1 → 1.2.2, with no breaking change.
 *
 * THE SEVEN ARE ACCEPTED, and the verdict rests on two facts rather than on
 * optimism. Both are asserted here, because an accepted risk whose premise
 * has quietly changed is worse than an open one:
 *
 *   1. puppeteer-core is a devDependency and there is NO runtime dependency
 *      (gsap was the only one, until Task 42 removed the motion registry
 *      that imported it). Nothing in that chain is compiled into a bundle, shipped to a
 *      browser, or installed on a server — a production deploy runs
 *      `npm ci --omit=dev` or no npm at all.
 *
 *   2. The harness names an existing Chrome through executablePath and never
 *      asks @puppeteer/browsers to download one, which is the only code path
 *      that reaches extract-zip. The remedy npm offers is puppeteer-core
 *      19.8.3 — a five-major-version downgrade that would break the harness
 *      outright — so the chain is kept and the path to it is closed.
 *
 * basic-ftp's advisory needs an FTP connection, get-uri and the proxy agents
 * need a PAC-configured proxy, and neither exists here.
 * ───────────────────────────────────────────────────────────────────────────
 */
class DependencySurfaceTest extends TestCase
{
    /** @return array{dependencies?: array<string, string>, devDependencies?: array<string, string>} */
    private function packageJson(): array
    {
        return json_decode((string) file_get_contents(base_path('package.json')), true);
    }

    public function test_no_dependency_reaches_a_browser(): void
    {
        $this->assertSame(
            [],
            array_keys($this->packageJson()['dependencies'] ?? []),
            'A new runtime dependency appeared. Every npm advisory in this project was '
            .'accepted on the grounds that the vulnerable packages are dev-only.'
        );
    }

    public function test_puppeteer_is_a_dev_dependency_and_imported_by_nothing_shipped(): void
    {
        $this->assertArrayHasKey('puppeteer-core', $this->packageJson()['devDependencies'] ?? []);

        /*
         * And nothing under resources/ imports it, so Vite never sees it and
         * no bundle can contain it. Asserted by reading the directory rather
         * than the bundle, because a bundle is only as current as the last
         * build.
         */
        $offenders = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path()) as $file) {
            if (! in_array($file->getExtension(), ['js', 'mjs', 'ts', 'css'], true)) {
                continue;
            }

            if (str_contains($file->getContents(), 'puppeteer')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The one code path that reaches extract-zip is a browser download, and
     * the harness does not take it.
     */
    public function test_the_harness_names_a_browser_rather_than_downloading_one(): void
    {
        $harness = (string) file_get_contents(base_path('bin/browser-check.mjs'));

        $this->assertStringContainsString('executablePath: chromePath()', $harness);
        $this->assertStringNotContainsString('BrowserFetcher', $harness);
        $this->assertStringNotContainsString('@puppeteer/browsers', $harness);

        // puppeteer-core, which ships no browser — not puppeteer, which does.
        $this->assertStringContainsString("from 'puppeteer-core'", $harness);
    }

    /**
     * source-map-js was the one advisory with a non-breaking fix, and it was
     * taken. This fails if a lockfile regenerates back onto the vulnerable
     * range (1.0.0 – 1.2.1).
     */
    public function test_the_advisory_that_had_a_fix_stays_fixed(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);

        $versions = [];

        foreach ($lock['packages'] ?? [] as $path => $meta) {
            if (str_ends_with($path, 'node_modules/source-map-js')) {
                $versions[] = $meta['version'];
            }
        }

        $this->assertNotEmpty($versions, 'source-map-js is no longer in the lockfile at all.');

        foreach ($versions as $version) {
            $this->assertTrue(
                version_compare($version, '1.2.2', '>='),
                "source-map-js {$version} is inside the GHSA-68fv-2mgg-jv7q range."
            );
        }
    }
}
