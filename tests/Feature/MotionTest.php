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
 * server hides nothing, that the LCP image is untouched, that the right
 * modules are asked for, and that the payload is inside its budget. The
 * choreography itself is measured by the browser harness.
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
     * This is the contract the whole feature rests on. Every initial hidden
     * state lives in motion.css behind a `.motion` class that only an inline
     * script writes — so a visitor with JavaScript off receives a finished
     * page, and there is nothing in the server's HTML for them to be missing.
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
     * is not a target of any opacity rule — the reveal clips its CONTAINER.
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

        /*
         * And the stylesheet never animates it either. The hero's initial
         * states name .hero__plate and .hero__wordmark; if a future edit adds
         * .hero__image to an opacity rule, this fails.
         */
        $css = file_get_contents(resource_path('css/components/motion.css'));

        $this->assertMatchesRegularExpression(
            '/\.motion[^{]*\.hero__plate\s*\{[^}]*clip-path/',
            $css,
            'the reveal must clip the plate'
        );

        foreach ($this->rulesTargeting($css, '.hero__image') as $rule) {
            $this->assertStringNotContainsString('opacity', $rule, 'the LCP image is inside an opacity rule');
            $this->assertStringNotContainsString('clip-path', $rule, 'the LCP image is inside a clip rule');
        }
    }

    // ----------------------------------------------------------------- 3

    /**
     * REDUCED MOTION PRODUCES NO SEQUENCE AND NO INDICATOR.
     *
     * The bootstrap refuses before anything else runs, so the gating class is
     * never written and no initial state ever applies. Asserted here on the
     * script's own source, because the decision is made before any code this
     * suite can execute.
     */
    public function test_reduced_motion_is_refused_at_the_bootstrap(): void
    {
        $boot = file_get_contents(resource_path('views/components/motion-boot.blade.php'));

        // The very first thing it does, before the session check.
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion: reduce.*?\)\.matches\)\s*return;/s',
            $boot,
            'the bootstrap does not refuse reduced motion first'
        );

        /*
         * And a belt for those braces: the stylesheet neutralises every
         * initial state under a reduced-motion query, so even a bug that
         * wrote the class could not produce motion.
         */
        $css = file_get_contents(resource_path('css/components/motion.css'));

        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);

        $guard = substr($css, (int) strpos($css, '@media (prefers-reduced-motion: reduce)'));

        foreach (['.hero__plate', '.hero__wordmark', '.motion-progress', 'data-reveal-pending'] as $target) {
            $this->assertStringContainsString($target, $guard, "{$target} is not neutralised under reduced motion");
        }
    }

    // ----------------------------------------------------------------- 4

    /**
     * THE REGISTRY ONLY IMPORTS WHAT A PAGE USES.
     *
     * The homepage declares a hero sequence, a parallax, reveals and link
     * rules. The journal declares reveals alone, and the archive — the
     * heaviest page — declares nothing at all.
     */
    public function test_each_page_opts_into_only_the_motion_it_needs(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(4), ['landscape'], year: 2025);
        Project::query()->first()?->forceFill(['is_featured' => true])->save();
        Article::factory()->create();
        Service::factory()->create();

        $expectations = [
            '/' => ['hero-develop', 'parallax', 'reveal', 'rule'],
            '/journal' => ['reveal'],
            '/services' => ['reveal', 'rule'],
            '/archive' => [],
        ];

        foreach ($expectations as $url => $expected) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('/data-motion="([^"]+)"/', $html, $matches);

            $found = [];

            foreach ($matches[1] as $value) {
                foreach (preg_split('/\s+/', $value) as $name) {
                    if ($name !== '') $found[$name] = true;
                }
            }

            $found = array_keys($found);
            sort($found);
            sort($expected);

            $this->assertSame($expected, $found, "{$url} opts into the wrong motion modules");
        }
    }

    /** Every name in the markup has a module behind it, and vice versa. */
    public function test_every_declared_motion_name_has_a_module(): void
    {
        $registry = file_get_contents(resource_path('js/public/motion/registry.js'));

        preg_match_all("/'([a-z-]+)': \(\) => import\('\.\/([a-z-]+)'\)/", $registry, $matches);

        $registered = $matches[1];

        $this->assertNotEmpty($registered, 'the registry has no modules');

        // Each one resolves to a file that exists.
        foreach ($matches[2] as $file) {
            $this->assertFileExists(resource_path("js/public/motion/{$file}.js"));
        }

        // And every name used in a template is registered.
        $used = [];

        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $view) {
            preg_match_all('/data-motion="([^"]+)"/', (string) file_get_contents($view), $found);

            foreach ($found[1] as $value) {
                foreach (preg_split('/\s+/', $value) as $name) {
                    if ($name !== '') $used[$name] = $view;
                }
            }
        }

        foreach ($used as $name => $view) {
            $this->assertContains(
                $name,
                $registered,
                "{$view} declares data-motion=\"{$name}\" but the registry has no such module"
            );
        }
    }

    // ----------------------------------------------------------------- 5

    /**
     * THE HOMEPAGE JS PAYLOAD IS UNDER BUDGET.
     *
     * 60 kB gzipped, GSAP included. Measured against the real built manifest —
     * the entry, its static imports, every chunk the homepage's data-motion
     * names pull in, and ScrollTrigger, which the parallax fetches on demand.
     *
     * Printed as well as asserted, because a budget nobody can see is a budget
     * nobody maintains.
     */
    public function test_the_homepage_javascript_is_within_budget(): void
    {
        $manifest = $this->manifest();

        $entry = $manifest['resources/js/public.js'] ?? null;

        $this->assertNotNull($entry, 'the public entry is not in the manifest — run npm run build');

        /*
         * Everything the homepage downloads, worst case, walked TRANSITIVELY.
         *
         * A one-level walk missed GSAP entirely and reported 18 kB: the entry
         * no longer imports GSAP directly — the registry reaches it through
         * core.js, which is itself a dynamic chunk — so following only the
         * entry's own imports counts the scenery and not the set.
         */
        $files = [];

        $collect = function (string $key) use (&$collect, $manifest, &$files): void {
            if (! isset($manifest[$key]) || in_array($manifest[$key]['file'], $files, true)) {
                return;
            }

            $files[] = $manifest[$key]['file'];

            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                $collect($import);
            }
        };

        $collect('resources/js/public.js');

        // The motion core, which is what actually pulls GSAP in.
        $collect('resources/js/public/motion/core.js');

        foreach (['hero-develop', 'parallax', 'reveal', 'rule'] as $name) {
            $key = "resources/js/public/motion/{$name}.js";

            $this->assertArrayHasKey($key, $manifest, "{$name} is not a separate chunk — code splitting broke");

            $collect($key);
        }

        // ScrollTrigger, dynamically imported by the parallax.
        $this->assertArrayHasKey(
            'node_modules/gsap/ScrollTrigger.js',
            $manifest,
            'ScrollTrigger is not a separate chunk'
        );

        $collect('node_modules/gsap/ScrollTrigger.js');

        // The walk must have reached GSAP, or the number below means nothing.
        $this->assertTrue(
            (bool) array_filter($files, static fn (string $f): bool => str_contains($f, 'vendor-public')),
            'the walk never reached GSAP — the budget figure would be fiction'
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
     * And a page with NO motion pays almost nothing: the entry alone, because
     * GSAP itself is behind a dynamic import the registry only reaches when
     * there is something to animate.
     */
    public function test_a_page_without_motion_does_not_download_gsap(): void
    {
        $manifest = $this->manifest();

        $entry = $manifest['resources/js/public.js'];

        $vendor = array_filter(
            $entry['imports'] ?? [],
            static fn (string $key): bool => str_contains($key, 'vendor')
        );

        /*
         * The entry MAY statically import a small shared chunk, but GSAP must
         * not be in it — the archive would otherwise pay 31 kB for an
         * animation it deliberately does not run.
         */
        foreach ($vendor as $key) {
            $bytes = strlen((string) gzencode(
                (string) file_get_contents(public_path('build/'.$manifest[$key]['file'])),
                9
            ));

            $this->assertLessThan(
                8 * 1024,
                $bytes,
                'the public entry statically imports a large vendor chunk: '.$manifest[$key]['file']
            );
        }

        // core.js, which is what imports GSAP, is a chunk of its own.
        $this->assertArrayHasKey(
            'resources/js/public/motion/core.js',
            $manifest,
            'motion core is not split out — every page would download GSAP'
        );
    }

    // ----------------------------------------------------------------- 6

    /**
     * THE SETTLED HERO IS THE SAME HTML EITHER WAY.
     *
     * The server sends one thing. Whether the sequence plays is decided in the
     * browser and leaves no trace in the markup — GSAP's clearProps removes
     * every inline style it wrote, and the gating class lives on <html>, not
     * on the hero.
     */
    public function test_the_hero_markup_is_identical_whether_or_not_motion_runs(): void
    {
        $first = $this->extractHero($this->homepage());

        // A second request is a different session as far as the server is
        // concerned, and must produce the same bytes.
        $second = $this->extractHero($this->get('/')->assertOk()->getContent());

        $this->assertSame($first, $second);

        // The hero carries the opt-in attribute and nothing else about motion.
        $this->assertStringContainsString('data-motion="hero-develop parallax rule"', $first);
        $this->assertStringNotContainsString('motion-hero-done', $first);
        $this->assertStringNotContainsString('data-reveal-pending', $first);
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
         * <script> tag and one class name to the response; it touches no
         * model, no setting and no cache, so if any of these move, something
         * other than motion moved them.
         */
        // 11 → 12 in Task 34 D2, for the testimonial strip's one flat select.
        $this->assertSame(12, $measure('/'), 'the homepage query count moved');
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
