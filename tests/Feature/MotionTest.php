<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsNoJavaScript;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * The motion contract, asserted against the SERVER's output.
 *
 * A browser cannot be run here, so what these tests can prove is everything
 * that must be true before a single byte of JavaScript executes: that the
 * server hides nothing, that the LCP image is untouched, that nothing is
 * registered for a system that no longer exists, and that the payload is
 * inside its budget. The arrivals themselves are measured in a browser:
 * tests/Support/arrive-check.mjs, statement-check.mjs and
 * second-view-check.mjs.
 *
 * TASK 42 ITEM 2 REWROTE THIS FILE. It used to assert the GSAP registry —
 * its bootstrap, its module map, its chunks — and every one of those
 * assertions passed while the registry refused to start on every page after
 * a session's first. A test of the wiring could not see that the wiring was
 * switched off. The registry is gone; what is left here is what the server
 * sends.
 */
class MotionTest extends TestCase
{
    use AssertsNoJavaScript;
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();

        $this->world = new DemoWorld();
    }

    private function homepage(): string
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(6), ['landscape'], year: 2025);

        Project::query()->first()?->forceFill(['is_featured' => true])->save();

        Article::factory()->count(3)->create();
        Service::factory()->count(3)->create();

        return $this->get('/')->assertOk()->getContent();
    }

    // ----------------------------------------------------------------- 1

    /**
     * WITH JAVASCRIPT DISABLED THE HERO RENDERS COMPLETE.
     *
     * This is the contract the whole feature rests on. The arrivals draw on a
     * canvas over content that is never itself hidden — so a visitor with
     * JavaScript off receives a finished page, and there is nothing in the
     * server's HTML for them to be missing.
     */
    public function test_the_server_sends_no_hidden_initial_state(): void
    {
        $html = $this->homepage();

        $this->assertNoHiddenInitialState($html, '/');

        // And the hero's own content is all present and readable.
        foreach (['hero__wordmark', 'hero__plate', 'hero__statement', 'hero__stages'] as $part) {
            $this->assertStringContainsString($part, $html, "the hero is missing {$part}");
        }

        $this->assertStringContainsString(
            e((string) Settings::get('site.statement')),
            $html,
            'the statement is not in the server HTML'
        );
    }

    /** The same contract, on every public page that carries motion. */
    public function test_no_public_page_sends_a_hidden_initial_state(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        $this->world->story('salt-line', DemoWorld::simpleScore(4), ['landscape'], year: 2025);
        Article::factory()->create();
        Service::factory()->create();

        foreach (['/', '/work', '/services', '/journal', '/archive', '/about', '/contact', '/privacy'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertNoHiddenInitialState($html, $url);
            $this->assertNoExecutableJavaScript($html, $url);
        }
    }

    // ----------------------------------------------------------------- 2

    /**
     * THE LCP NODE IS UNTOUCHED.
     *
     * Chrome does not count an element with opacity: 0 toward Largest
     * Contentful Paint, and does not count one that has not painted. The hero
     * image therefore carries fetchpriority="high", no loading attribute, and
     * is not a target of any motion rule.
     *
     * Its one movement is the desktop parallax (arrive.js), a scroll-linked
     * transform that is identity at rest — so the frame Chrome records as the
     * LCP is the untransformed image, and at 390 it never moves at all.
     * arrive-check.mjs asserts that the dissolve never covers it.
     */
    public function test_the_hero_image_is_never_animated_or_deferred(): void
    {
        $html = $this->homepage();

        $image = $this->tagOf($html, 'hero__image');

        $this->assertNotNull($image, 'the hero has no image');
        $this->assertStringContainsString('fetchpriority="high"', $image);
        $this->assertStringNotContainsString('loading=', $image, 'the LCP image must never be lazy');
        $this->assertStringContainsString('decoding="sync"', $image);

        // Nothing in the server HTML dims it.
        $this->assertStringNotContainsString('opacity', $image);

        $css = file_get_contents(resource_path('css/components/motion.css'))
            .file_get_contents(resource_path('css/components/arrive.css'));

        /*
         * ───────────────────────────────────────────────────────────────────
         * TASK 36c A2 — THIS LOOP ASSERTED NOTHING, AND COULD NOT.
         *
         * Established by planting $this->fail() as its first statement: the
         * test still passed, so the body never ran. motion.css contains no
         * rule targeting .hero__image at all, which is the desired state —
         * and a loop that checks "every rule is acceptable" over a set that
         * is empty by design is permanently vacuous.
         *
         * The claim in the docblock above was still true: adding an opacity
         * rule would have made the loop run and fail. But the test asserted
         * nothing on every run until that day, which is exactly the shape
         * this task is sweeping for.
         *
         * So the population itself is asserted instead of its members. This
         * always executes, it says something stronger — the LCP image is not
         * targeted by a motion rule AT ALL, not merely spared opacity and
         * clip-path — and it names the offender when it fails.
         * ───────────────────────────────────────────────────────────────────
         */
        $this->assertSame(
            [],
            $this->rulesTargeting($css, '.hero__image'),
            'the LCP image is targeted by a motion rule'
        );
    }

    // ----------------------------------------------------------------- 3

    /**
     * NOTHING IS REGISTERED FOR A SYSTEM THAT NO LONGER EXISTS.
     *
     * Task 42 item 2: the GSAP registry switched itself off after a session's
     * first page, and its registrations — data-motion="hero-develop parallax
     * reveal rule" — went on looking live in every template. The brief's
     * words: do not leave a disabled system in the tree with live-looking
     * registrations in it. So no template names one and the module directory
     * is gone. That what remains RUNS on a second page view is asserted in a
     * browser, by tests/Support/second-view-check.mjs.
     */
    public function test_no_template_registers_motion_and_the_registry_is_gone(): void
    {
        $registrations = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $view) {
            // Comments stripped first: a note explaining the removal is not a registration.
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $view->getContents());

            if (preg_match('/\sdata-(?:motion|reveal-item)[\s=>]/', $markup)) {
                $registrations[] = $view->getRelativePathname();
            }
        }

        $this->assertSame([], $registrations, 'a template registers motion with no module behind it');
        $this->assertDirectoryDoesNotExist(resource_path('js/public/motion'));
        $this->assertFileDoesNotExist(resource_path('views/components/motion-boot.blade.php'));
    }

    // ----------------------------------------------------------------- 5

    /**
     * THE HOMEPAGE JS PAYLOAD IS UNDER BUDGET.
     *
     * 60 kB gzipped. Measured against the real built manifest — the entry,
     * its static imports, and the arrive layer it imports on demand. GSAP
     * used to be most of this figure; Task 42 removed it.
     *
     * Printed as well as asserted, because a budget nobody can see is a budget
     * nobody maintains.
     */
    /**
     * ───────────────────────────────────────────────────────────────────────
     * AMENDMENT 2 — THE BUDGET IS PER LOCALE, SO IT IS ASSERTED PER LOCALE.
     *
     * The budget test below walks the MANIFEST. That is the right way to
     * count a payload including dynamic imports, and it has one blind spot
     * built into it: it assumes the module set the ENGLISH homepage asks for.
     * Nothing checked that /ar asks for the same one, so a locale-specific
     * entry or an extra chunk on the Arabic page would have sailed past a
     * green budget — and the figure would have been "the homepage's", meaning
     * whichever homepage was measured.
     *
     * That is the shape of error Amendment 2 was written about: three of the
     * five budgets were stated site-wide while being English-only.
     *
     * So the two documents are compared. Equal asset sets plus the manifest
     * walk below equals a budget that covers both languages; either alone
     * does not.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_both_locales_ask_for_the_same_build_assets(): void
    {
        $assetsIn = function (string $path): array {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match_all('#/build/[A-Za-z0-9/._-]+#', (string) $html, $found);

            $assets = array_values(array_unique($found[0]));
            sort($assets);

            return $assets;
        };

        $english = $assetsIn('/');
        $arabic = $assetsIn('/ar');

        $this->assertNotEmpty($english, 'No build assets on the English homepage — run npm run build.');

        $this->assertSame(
            $english,
            $arabic,
            'The two locales load different build assets, so a single JS or CSS figure '
            .'describes only the language it was measured in.'
        );

        /*
         * And the one thing that legitimately DOES differ is the font, which
         * is deliberate and is not in the JS or CSS budget: unicode-range
         * means an English visitor fetches no Arabic face and the reverse.
         * Asserted here so that "the assets are identical" is not read as
         * "the page weight is identical", which it is not — measured at 390,
         * Arabic pulls 142.90 kB of woff2 against English's 117.98 kB.
         */
        $this->assertStringContainsString(
            'rel="preload" href="/fonts/archivo-latin.woff2"',
            (string) $this->get('/')->getContent(),
        );
        $this->assertStringContainsString(
            'rel="preload" href="/fonts/rubik-arabic.woff2"',
            (string) $this->get('/ar')->getContent(),
        );
    }

    public function test_the_homepage_javascript_is_within_budget(): void
    {
        $manifest = $this->manifest();

        $entry = $manifest['resources/js/public.js'] ?? null;

        $this->assertNotNull($entry, 'the public entry is not in the manifest — run npm run build');

        /*
         * Everything the homepage downloads, worst case, walked TRANSITIVELY
         * through static and dynamic imports alike. (A one-level walk once
         * missed GSAP entirely and reported 18 kB.)
         */
        $files = [];

        $collect = function (string $key) use (&$collect, $manifest, &$files): void {
            if (! isset($manifest[$key]) || in_array($manifest[$key]['file'], $files, true)) {
                return;
            }

            $files[] = $manifest[$key]['file'];

            foreach ([...($manifest[$key]['imports'] ?? []), ...($manifest[$key]['dynamicImports'] ?? [])] as $import) {
                $collect($import);
            }
        };

        $collect('resources/js/public.js');

        // The walk must have reached the arrive layer, or the number below means nothing.
        $this->assertContains(
            $manifest['resources/js/public/arrive/arrive.js']['file'] ?? null,
            $files,
            'the walk never reached the arrive layer — the budget figure would be fiction'
        );

        $total = 0;
        $rows = [];

        foreach ($files as $file) {
            $path = public_path('build/'.$file);

            $this->assertFileExists($path);

            $bytes = strlen((string) gzencode((string) file_get_contents($path), 9));
            $total += $bytes;
            $rows[] = sprintf('  %-42s %6.2f kB', basename($file), $bytes / 1024);
        }

        $budget = 60 * 1024;

        fwrite(STDERR, "\n  HOMEPAGE JAVASCRIPT (gzipped)\n".implode("\n", $rows)."\n");
        fwrite(STDERR, sprintf("  %-42s %6.2f kB  of %d kB\n\n", 'TOTAL', $total / 1024, $budget / 1024));

        $this->assertLessThan(
            $budget,
            $total,
            sprintf('the homepage ships %.2f kB gzipped, over the %d kB budget', $total / 1024, $budget / 1024)
        );
    }

    /**
     * AND NO BUILT ASSET CONTAINS GSAP. Read from the files themselves, not
     * from package.json: a dependency can be removed from the manifest of
     * dependencies and still be compiled into a stale chunk.
     */
    public function test_no_built_asset_contains_gsap(): void
    {
        $offenders = [];

        foreach ($this->manifest() as $chunk) {
            $contents = (string) file_get_contents(public_path('build/'.$chunk['file']));

            if (str_contains($contents, 'GreenSock') || str_contains($contents, 'ScrollTrigger')) {
                $offenders[] = $chunk['file'];
            }
        }

        $this->assertSame([], $offenders, 'a built asset still contains GSAP');
    }

    // ----------------------------------------------------------------- 6

    /**
     * THE SETTLED HERO IS THE SAME HTML EITHER WAY.
     *
     * The server sends one thing. Whether anything arrives is decided in the
     * browser and leaves no trace in the markup.
     */
    public function test_the_hero_markup_is_identical_whether_or_not_motion_runs(): void
    {
        $first = $this->extractHero($this->homepage());

        // A second request is a different session as far as the server is
        // concerned, and must produce the same bytes.
        $second = $this->extractHero($this->get('/')->assertOk()->getContent());

        $this->assertSame($first, $second);

        $this->assertStringNotContainsString('data-motion', $first);
        $this->assertStringNotContainsString('opacity', $first);
    }

    // ----------------------------------------------------------------- 7

    /** Motion is a client-side concern and costs the server nothing. */
    public function test_the_query_counts_are_unchanged(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(6), ['landscape'], year: 2025);
        Project::query()->first()?->forceFill(['is_featured' => true])->save();
        Article::factory()->count(3)->create();
        Service::factory()->count(3)->create();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $measure = function (string $url) use (&$count): int {
            $this->get($url);   // warm the site-wide maps

            $count = 0;
            $this->get($url)->assertOk();

            return $count;
        };

        /*
         * MOTION COSTS THE SERVER NOTHING, and these numbers say so.
         *
         * They are what this fixture's content requires — the homepage at
         * eleven, and the two index pages at the one or three selects their
         * own rows need. What matters is not the absolute number but that
         * introducing motion did not change it. Motion adds one
         * <script> tag to the response; it touches no
         * model, no setting and no cache, so if any of these move, something
         * other than motion moved them.
         */
        // 11 → 12 in Task 34 D2, for the testimonial strip's one flat select.
        // 12 -> 13: the cinematic sections, one select whatever their number (lighting brief, 22 Oct 2026).
        $this->assertSame(13, $measure('/'), 'the homepage query count moved');
        $this->assertSame(3, $measure('/journal'), 'the journal query count moved');
        $this->assertSame(1, $measure('/services'), 'the services query count moved');

        /*
         * And the strongest form of the claim: the response bytes are
         * identical whatever a client would do with them. There is no
         * server-side branch on motion at all.
         *
         * APART FROM THE CSP NONCE, which Task 36 C made fresh per request
         * and which is SUPPOSED to differ — a reused nonce is a nonce that
         * protects nothing. withoutNonces() replaces it with a fixed marker;
         * the count assertion below is what stops a page that has lost its
         * nonces entirely from passing this as "equal".
         */
        $plain = (string) $this->get('/')->getContent();
        $reduced = (string) $this->withHeaders(['Sec-CH-Prefers-Reduced-Motion' => 'reduce'])->get('/')->getContent();

        $this->assertGreaterThan(0, $this->nonceCount($plain), 'the page carries no CSP nonce at all');
        $this->assertSame($this->nonceCount($plain), $this->nonceCount($reduced));

        $this->assertSame(
            $this->withoutNonces($plain),
            $this->withoutNonces($reduced),
            'the server varies its output on a motion hint'
        );

        /*
         * And the nonce itself really did change, which is the other half of
         * the claim and the half a normalising comparison would otherwise
         * hide.
         */
        $this->assertNotSame($plain, $reduced, 'the nonce was reused between two requests');
    }

    // ----------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = public_path('build/manifest.json');

        $this->assertFileExists($path, 'no build manifest — run npm run build');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The full opening tag of the first element carrying a class. */
    private function tagOf(string $html, string $class): ?string
    {
        preg_match('/<[a-z]+[^>]*class="[^"]*'.preg_quote($class, '/').'[^"]*"[^>]*>/i', $html, $match);

        return $match[0] ?? null;
    }

    /** Every CSS rule whose selector mentions a target. */
    private function rulesTargeting(string $css, string $target): array
    {
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $match) {
            if (str_contains($match[1], $target)) $rules[] = $match[0];
        }

        return $rules;
    }

    private function extractHero(string $html): string
    {
        $start = strpos($html, '<section class="hero');

        $this->assertNotFalse($start, 'no hero on the page');

        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end - $start);
    }
}
