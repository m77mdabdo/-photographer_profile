<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Projects\SyncProjectMedia;
use App\Enums\MediaStatus;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use \Tests\Support\AssertsNoJavaScript;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        $this->seedSettings();
    }

    /**
     * The editable copy the page needs in order to render at all.
     *
     * The static memo inside Settings survives between tests in one process,
     * and RefreshDatabase does not know about it, so it is cleared explicitly
     * — otherwise the second test in a run reads the first one's values out of
     * a map whose rows no longer exist.
     */
    private function seedSettings(): void
    {
        \App\Support\Settings::flush();

        $this->seed(\Database\Seeders\SettingsSeeder::class);

        \App\Support\Settings::flush();
    }


    private function media(int $width = 4000, int $height = 2667): Media
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready, 'width' => $width, 'height' => $height,
            'alt' => 'A coastline at first light',
            'dominant_color' => '#5a4a38',
        ]);

        foreach ([['webp', 768], ['avif', 480], ['jpeg', 1920]] as [$format, $width]) {
            $path = sprintf('2026/09/%s/%d.%s', $media->uuid, $width, $format === 'jpeg' ? 'jpg' : $format);
            Storage::disk('media_public')->put($path, 'x');
            $media->variants()->create([
                'format' => $format, 'width' => $width, 'height' => (int) ($width * 2 / 3),
                'disk' => 'media_public', 'path' => $path, 'size_bytes' => 4096,
            ]);
        }

        return $media->refresh();
    }

    private function populate(int $projects = 8, string $featuredShape = 'landscape'): void
    {
        $categories = Category::factory()->count(3)->create();

        [$fw, $fh] = match ($featuredShape) {
            'portrait' => [2667, 4000],
            'square' => [3000, 3000],
            default => [4000, 2667],
        };

        for ($i = 0; $i < $projects; $i++) {
            $cover = $i === 0 ? $this->media($fw, $fh) : $this->media();

            $project = Project::factory()->published()->create([
                'title' => 'Series Number '.($i + 1),
                'slug' => 'series-'.($i + 1),
                'is_featured' => $i === 0,
                'sort_order' => $i,
                'cover_media_id' => $cover->id,
                'hero_media_id' => $cover->id,
            ]);

            $project->categories()->attach($categories->random(2)->pluck('id'));
            app(SyncProjectMedia::class)($project);
        }
    }

    // ----------------------------------------------------------------- 1

    public function test_the_homepage_renders_every_section(): void
    {
        $this->populate();

        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();

        foreach (['class="hero', 'class="intro"', 'class="works"',
                  'class="story"', 'class="sheet"', 'class="closing"'] as $section) {
            $this->assertStringContainsString($section, $html, "missing {$section}");
        }

        // And each section holds real content, not just a shell.
        $this->assertStringContainsString('Work, in series', $html);
        $this->assertStringContainsString('Loose frames', $html);
        $this->assertStringContainsString('The diary is open for next year.', $html);
    }

    // ----------------------------------------------------------------- 2

    // ----------------------------------------------------------------- 3

    public function test_drafts_and_archived_projects_never_appear(): void
    {
        $this->populate(3);

        $draft = Project::factory()->create(['title' => 'Secret Draft Work', 'slug' => 'secret-draft']);
        $archived = Project::factory()->archived()->create(['title' => 'Retired Old Work', 'slug' => 'retired']);
        $scheduled = Project::factory()->scheduled()->create(['title' => 'Next Week Work', 'slug' => 'next-week']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Secret Draft Work', $html);
        $this->assertStringNotContainsString('Retired Old Work', $html);
        $this->assertStringNotContainsString('Next Week Work', $html);
        $this->assertStringContainsString('Series Number 1', $html);
    }

    // ----------------------------------------------------------------- 4

    public function test_the_page_renders_with_no_projects_at_all(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $html = $response->getContent();

        // An empty state, not a broken shell.
        $this->assertStringContainsString('Nothing published yet', $html);
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringNotContainsString('class="works"', $html);
        $this->assertStringNotContainsString('class="sheet"', $html);

        // Media with no projects still fills the contact sheet.
        $this->media();
        $withMedia = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('class="sheet"', $withMedia);
    }

    // ----------------------------------------------------------------- 5

    // ----------------------------------------------------------------- 6

    public function test_the_heading_order_is_valid(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<h([1-6])\b/', $html, $matches);
        $levels = array_map('intval', $matches[1]);

        $this->assertSame(1, count(array_filter($levels, fn (int $l): bool => $l === 1)), 'exactly one h1');
        $this->assertSame(1, $levels[0], 'the first heading is the h1');

        // No level is skipped on the way down.
        $previous = 0;
        foreach ($levels as $level) {
            if ($previous !== 0) {
                $this->assertLessThanOrEqual($previous + 1, $level, "h{$previous} is followed by h{$level}");
            }
            $previous = $level;
        }
    }

    // ----------------------------------------------------------------- 7

    public function test_no_project_content_is_hardcoded_in_the_templates(): void
    {
        $templates = array_merge(
            glob(resource_path('views/components/home/*.blade.php')) ?: [],
            [resource_path('views/home.blade.php')]
        );

        $this->assertNotEmpty($templates);

        foreach ($templates as $template) {
            $source = file_get_contents($template);

            // The demo seeder's titles must not have leaked into any view.
            foreach (['Coastline in Winter', 'The Quiet Hours', 'Studio Portraits',
                      'Salt and Iron', 'After the Harvest', 'Lorem ipsum'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    basename($template).' contains hardcoded content: '.$forbidden
                );
            }
        }

        // Every project title on the rendered page comes from the database.
        $this->populate(3);
        $html = $this->get('/')->getContent();

        foreach (Project::published()->pluck('title') as $title) {
            $this->assertStringContainsString($title, $html);
        }
    }

    // ----------------------------------------------------------------- 8

    public function test_the_page_contains_no_script_tags_at_all(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertNoExecutableJavaScript($html);
        $this->assertStringNotContainsString('livewire', strtolower($html));
        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    // ================= TASK 11 =================

    // ----------------------------------------------------------------- 4

    public function test_only_the_display_face_is_preloaded(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        /*
         * The display face sets the wordmark, which is the largest thing on
         * the page and the only swap a visitor would plainly see. The mono is
         * above the fold too, but at eleven pixels against a metric-matched
         * fallback its swap costs a glyph substitution and no reflow — not
         * worth taking bandwidth from the LCP photograph.
         */
        $this->assertStringContainsString(
            '<link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>',
            $html
        );

        $this->assertStringNotContainsString('inter-tight', $html);
        $this->assertStringNotContainsString('jetbrains-mono', $html);
        $this->assertSame(1, substr_count($html, 'as="font"'), 'exactly one font is preloaded');

        // Nothing is fetched from a third party.
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }

        // And every face is actually self-hosted, with its licence beside it.
        foreach (['archivo-latin', 'archivo-latin-ext',
                  'inter-tight-latin', 'inter-tight-latin-ext',
                  'jetbrains-mono-latin', 'jetbrains-mono-latin-ext'] as $file) {
            $this->assertFileExists(public_path("fonts/{$file}.woff2"));
        }

        foreach (['archivo', 'inter-tight', 'jetbrains-mono'] as $family) {
            $this->assertFileExists(public_path("fonts/LICENSE-{$family}.txt"));
        }
    }

    public function test_the_font_faces_declare_metric_matched_fallbacks(): void
    {
        $css = file_get_contents(resource_path('css/fonts.css'));

        // Without these the swap reflows every line of the largest text.
        foreach (['size-adjust', 'ascent-override', 'descent-override', 'font-display: swap'] as $property) {
            $this->assertStringContainsString($property, $css);
        }

        foreach (["'Archivo Fallback'", "'Inter Fallback'", "'Mono Fallback'"] as $fallback) {
            $this->assertStringContainsString('font-family: '.$fallback, $css);
        }

        /*
         * The display fallback must itself be condensed. Archivo is drawn at
         * 70% width here; falling back to a normal-width grotesque would hold
         * the line height and still reflow every line of the wordmark.
         */
        $this->assertMatchesRegularExpression(
            "/font-family: 'Archivo Fallback';\s*src: local\('Arial Narrow'\)/",
            $css
        );

        // The tokens are the only place the families are named.
        $tokens = file_get_contents(resource_path('css/tokens.css'));
        $this->assertStringContainsString("--font-display: 'Archivo'", $tokens);
        $this->assertStringContainsString("--font-sans: 'Inter Tight'", $tokens);
        $this->assertStringContainsString("--font-mono: 'JetBrains Mono'", $tokens);

        /*
         * Bodoni is retired, not merely unused. A Didone and a heavy condensed
         * grotesque are both display faces competing for one job.
         *
         * Comments are stripped first. Both files explain at length why the
         * old face went and why its optical correction pointed the other way,
         * and naming a decision must not read as still making it.
         */
        $strip = static fn (string $source): string => (string) preg_replace('/\/\*.*?\*\//s', '', $source);

        $this->assertStringNotContainsString('Bodoni', $strip($tokens));
        $this->assertStringNotContainsString('Bodoni', $strip($css));
        $this->assertFileDoesNotExist(public_path('fonts/bodoni-moda-latin.woff2'));
        $this->assertFileDoesNotExist(public_path('fonts/bodoni-moda-latin-ext.woff2'));
    }

    public function test_the_display_weight_is_retuned_per_register(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));

        preg_match_all('/--wght-display:\s*(\d+)/', $tokens, $matches);

        $weights = array_map('intval', $matches[1]);

        $this->assertGreaterThanOrEqual(3, count($weights), 'dark plus both light declarations');

        $dark = $weights[0];
        $light = $weights[1];

        /*
         * The direction reversed when the face did. A Didone needs MORE weight
         * on black to stop its hairlines being eaten; a grotesque has no
         * hairlines, but its counters close up when light bleeds into them, so
         * it needs LESS. Anything that puts these back the old way round is a
         * regression, not a preference.
         */
        $this->assertLessThan($light, $dark, 'the grotesque is set lighter on a dark ground, not heavier');
        $this->assertGreaterThan(700, $dark, 'the display face is heavy in both registers');
    }

    // ----------------------------------------------------------------- 5

    public function test_the_page_still_contains_no_script_tags(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertNoExecutableJavaScript($html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('wire:', $html);
    }

    // ----------------------------------------------------------------- 6

    public function test_the_grain_layer_is_inert_and_costs_no_request(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="texture" aria-hidden="true">', $html);
        $this->assertStringContainsString('<div class="vignette" aria-hidden="true">', $html);

        $css = file_get_contents(resource_path('css/components/texture.css'));

        // It must never intercept a pointer, or it would break every link
        // underneath it.
        $this->assertMatchesRegularExpression(
            '/\.texture,\s*\.vignette\s*\{[^}]*pointer-events:\s*none/s',
            $css
        );

        // Inline, so no extra request and nothing to cache.
        $this->assertStringContainsString('data:image/svg+xml', $css);
        $this->assertStringNotContainsString('.png', $css);
    }

    // ----------------------------------------------------------------- 7

    public function test_reduced_motion_disables_the_vignette_animation(): void
    {
        $css = file_get_contents(resource_path('css/components/texture.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.vignette\s*\{[^}]*animation:\s*none/s',
            $css
        );

        // The global rule in base.css only shortens durations; an animation
        // that still runs holds its final keyframe, so this one is stopped
        // outright and settled on a fixed value. That value is now a token, so
        // it settles on whatever the active register says — which on a light
        // ground is zero, because the vignette is switched off there.
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.vignette\s*\{[^}]*opacity:\s*var\(--vignette-opacity-max\)/s',
            $css
        );
    }

    // ----------------------------------------------------------------- 8

    // ----------------------------------------------------------------- 9

    public function test_no_public_stylesheet_holds_a_colour_literal_except_tokens(): void
    {
        $files = array_merge(
            glob(resource_path('css/*.css')) ?: [],
            glob(resource_path('css/components/*.css')) ?: [],
            glob(resource_path('css/pages/*.css')) ?: []
        );

        $this->assertNotEmpty($files);

        $offenders = [];

        foreach ($files as $file) {
            if (in_array(basename($file), ['tokens.css', 'admin.css', 'auth.css'], true)) {
                continue;   // tokens defines them; admin is not the public bundle
            }

            $source = file_get_contents($file);

            foreach (preg_split('/\R/', $source) as $number => $line) {
                if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgb\(\s*\d|hsl\(/', $line) === 1) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "colour literals outside tokens.css:\n".implode("\n", $offenders));
    }

    // ----------------------------------------------------------------- extra

    public function test_the_accent_marks_only_the_primary_action(): void
    {
        $css = file_get_contents(resource_path('css/pages/home.css'));

        preg_match_all('/^\s*([^{}]+)\{([^}]*var\(--accent\)[^}]*)\}/m', $css, $matches, PREG_SET_ORDER);

        /*
         * THE RULE CHANGED, DELIBERATELY.
         *
         * Until now the accent marked one thing: the primary action. The hero
         * detail layer gives it a second job — it is the colour of the
         * TECHNICAL voice, the register that names the frame, credits the
         * photographer and marks which stage of the sequence you are at.
         *
         * That is still a rule, and it is still narrow. What it is not is the
         * old rule, so the list below is the new one written out rather than
         * the old one quietly widened. Anything not on it is a leak.
         */
        $permitted = '/link-rule|closing__rule|hero__sequence|hero__attribution|hero__stage|marquee__item/';

        foreach ($matches as [, $selector, $body]) {
            $clean = trim(preg_replace('/\/\*.*?\*\//s', '', $selector) ?? '');

            /*
             * At-rule wrappers are not selectors; the rules inside them are
             * matched on their own. And a rule whose only mention of the
             * accent is DEFINING --hero-accent is declaring the fallback the
             * derived colour drops to, not spending the colour on anything.
             */
            if ($clean === '' || str_starts_with($clean, '@')
                || str_contains($clean, ':root')
                || str_contains($body, '--hero-accent:')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                $permitted,
                $clean,
                "the accent leaked into: {$clean}"
            );
        }

        // Specifically, it was removed from the index numerals and eyebrows.
        $this->assertMatchesRegularExpression('/\.work__index \{[^}]*--text-secondary/s', $css);
        /*
         * The eyebrow used to be asserted as `color: var(--text-secondary)`.
         * Task 38 C2 measured that grey over the featured photograph at
         * 1.06:1 against the worst-case pixel — it cannot clear 4.5:1 at any
         * alpha the scrim allows — and moved it to the primary colour. What
         * this test is for is that the ACCENT stays off it, so that is what
         * it asserts now; the colour itself is asserted on the render by
         * tests/Support/register-contrast.mjs.
         */
        $this->assertDoesNotMatchRegularExpression('/\.story__eyebrow \{[^}]*--accent/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.story__eyebrow \{[^}]*--hero-accent/s', $css);
    }

    public function test_the_vertical_rhythm_is_not_one_repeated_interval(): void
    {
        $css = file_get_contents(resource_path('css/pages/home.css'));

        foreach (['--rhythm-approach', '--rhythm-settle', '--rhythm-between', '--rhythm-breath'] as $token) {
            $this->assertStringContainsString($token, $css, "{$token} is unused");
        }

        // The old uniform interval is gone from the section padding.
        $this->assertStringNotContainsString('padding: var(--sp-13) var(--gutter) var(--sp-12)', $css);
    }

    // ================= THE HERO =================

    /*
     * One hero: the horizontal wordmark interrupted by an opaque plate. The
     * vertical variant that briefly replaced it is gone, and a test at the end
     * of this block fails if any trace of it comes back.
     */

    public function test_the_wordmark_is_one_intact_text_node(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();
        $name = config('seo.site_name');

        $this->assertSame(1, substr_count($html, '<h1'), 'exactly one h1');
        $this->assertMatchesRegularExpression(
            '/<h1 class="hero__wordmark" id="hero-title">'.preg_quote($name, '/').'<\/h1>/',
            $html,
            'the wordmark must be a single text node'
        );

        /*
         * Nothing fragments it. Uppercase is a CSS transform, so the word is
         * announced once, copies as one word, and keeps its real capitals.
         */
        $this->assertStringNotContainsString('wordmark__letter', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*<span/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>[^<]*<br/', $html);

        $css = file_get_contents(resource_path('css/pages/home.css'));
        $this->assertMatchesRegularExpression(
            '/\.hero__wordmark \{[^}]*text-transform: uppercase/s',
            $css,
            'the capitals are a style, not a second copy of the name'
        );
    }

    public function test_the_hero_image_is_high_priority_and_never_lazy(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $hero = substr($html, (int) strpos($html, 'class="hero'), 5000);

        $this->assertStringContainsString('fetchpriority="high"', $hero);
        $this->assertStringContainsString('decoding="sync"', $hero);

        $img = substr($hero, (int) strpos($hero, '<img'));
        $img = substr($img, 0, (int) strpos($img, '>'));

        $this->assertStringNotContainsString('loading=', $img, 'the LCP image must never be lazy');

        // Below the fold, lazy loading is still in force.
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_the_page_has_no_script_tags(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertNoExecutableJavaScript($html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('wire:', $html);
    }

    public function test_the_hero_renders_with_no_featured_project(): void
    {
        $this->seedSettings();

        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('hero--empty', $html);
        $this->assertStringContainsString('<h1 class="hero__wordmark"', $html);
        $this->assertStringNotContainsString('hero__plate', $html);

        // The parts that belong to the studio rather than to a project stay.
        $this->assertStringContainsString('hero__statement', $html);
        $this->assertStringContainsString('hero__stages', $html);

        // The parts that belong to a project do not.
        $this->assertStringNotContainsString('hero__rail', $html);
        $this->assertStringNotContainsString('hero__link', $html);
    }

    /**
     * Each shape must produce a genuinely different composition, not the same
     * layout under a different class name.
     */
    public function test_each_orientation_composes_differently(): void
    {
        foreach (['landscape', 'portrait', 'square'] as $shape) {
            Project::withTrashed()->get()->each->forceDelete();
            Media::withTrashed()->get()->each->forceDelete();

            $this->populate(4, $shape);

            $html = $this->get('/')->assertOk()->getContent();

            $this->assertStringContainsString('hero--'.$shape, $html, $shape);

            foreach (array_diff(['landscape', 'portrait', 'square'], [$shape]) as $other) {
                $this->assertStringNotContainsString('hero--'.$other, $html, $shape.' leaked '.$other);
            }
        }

        $css = file_get_contents(resource_path('css/pages/home.css'));

        // The plate takes a different column in each case ...
        preg_match('/\.hero--landscape \.hero__plate \{ grid-column: ([^;]+); \}/', $css, $landscape);
        preg_match('/\.hero--portrait\s+\.hero__plate \{ grid-column: ([^;]+); \}/', $css, $portrait);
        preg_match('/\.hero--square\s+\.hero__plate \{ grid-column: ([^;]+); \}/', $css, $square);

        $columns = [
            'landscape' => $landscape[1] ?? '',
            'portrait' => $portrait[1] ?? '',
            'square' => $square[1] ?? '',
        ];

        $this->assertNotContains('', $columns, 'every orientation places the plate: '.json_encode($columns));
        $this->assertSame(3, count(array_unique($columns)), 'the three columns must differ: '.json_encode($columns));

        // ... and the supporting text moves to the side the picture leaves,
        // with clearance from it — see test_no_hero_text_shares_a_column.
        $this->assertMatchesRegularExpression('/\.hero--portrait \.hero__aside \{\s*grid-column: 1 \/ span 5;/s', $css);
        $this->assertMatchesRegularExpression('/\.hero--landscape \.hero__aside \{\s*grid-column: 10 \/ -1;/s', $css);
    }

    /**
     * The derived accent must land inside the lightness band for every shape
     * in both registers — or refuse and fall back. A colour that is merely
     * high-contrast is not enough: on a dark ground it must not be a second
     * white, and on paper it must not read as body text.
     */
    public function test_the_accent_band_holds_for_every_orientation_in_both_modes(): void
    {
        $grounds = ['dark' => '#0b0b0c', 'light' => '#f4f1ea'];

        foreach (['landscape', 'portrait', 'square'] as $shape) {
            Project::withTrashed()->get()->each->forceDelete();
            Media::withTrashed()->get()->each->forceDelete();

            $this->populate(3, $shape);

            $html = $this->get('/')->assertOk()->getContent();

            foreach ($grounds as $mode => $background) {
                preg_match('/--hero-accent-'.$mode.': (#[0-9a-f]{6})/i', $html, $found);

                $this->assertNotEmpty($found, "{$shape}/{$mode}: no accent was emitted");

                $colour = $found[1];
                [$low, $high] = \App\Support\AccentColor::bandFor($background);
                $lightness = \App\Support\AccentColor::lightnessOf($colour);

                $this->assertNotNull($lightness, "{$shape}/{$mode}: {$colour} did not parse");
                $this->assertGreaterThanOrEqual($low, $lightness, "{$shape}/{$mode}: {$colour} is below the band");
                $this->assertLessThanOrEqual($high, $lightness, "{$shape}/{$mode}: {$colour} is above the band");

                $this->assertGreaterThanOrEqual(
                    \App\Support\AccentColor::TARGET,
                    \App\Support\AccentColor::contrastAgainst($colour, $background),
                    "{$shape}/{$mode}: {$colour} fails the contrast floor"
                );
            }
        }

        // With nothing to derive from, the hero silently keeps the token accent.
        Media::query()->update(['dominant_color' => null]);

        $plain = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('--hero-accent-dark', $plain);
        $this->assertStringContainsString('hero__wordmark', $plain);
    }

    /**
     * The readability guarantee is structural, not statistical: the plate is
     * opaque and sits above the wordmark, so a letter is either fully on the
     * page ground or fully hidden. Nothing here may be solved with a scrim, a
     * gradient or a shadow, because those only make the average case look
     * acceptable.
     */
    public function test_the_plate_is_opaque_and_the_guarantee_is_structural(): void
    {
        $css = file_get_contents(resource_path('css/pages/home.css'));

        $start = strpos($css, '/* ---------------- 1. Hero');
        $end = strpos($css, '/* ---------------- 2. Introduction');
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        $hero = substr($css, $start, $end - $start);

        // Opaque fill, so nothing behind it can show through.
        $this->assertMatchesRegularExpression(
            '/\.hero__plate \{[^}]*background-color: var\(--bg\)/s',
            $hero,
            'the plate must carry an opaque background'
        );

        // Stacked above the wordmark, so occlusion is total where it happens.
        $this->assertMatchesRegularExpression('/\.hero__wordmark \{[^}]*z-index: var\(--z-base\)/s', $hero);
        $this->assertMatchesRegularExpression('/\.hero__plate \{[^}]*z-index: var\(--z-media\)/s', $hero);

        $tokens = file_get_contents(resource_path('css/tokens.css'));
        preg_match('/--z-base: (\d+)/', $tokens, $base);
        preg_match('/--z-media: (\d+)/', $tokens, $media);
        $this->assertGreaterThan((int) $base[1], (int) $media[1], 'the plate must stack above the wordmark');

        /*
         * No softening anywhere in the composition. Comments are stripped
         * first: the section's prose explains why there is no scrim, and
         * explaining it must not read as doing it.
         */
        $declarations = (string) preg_replace('/\/\*.*?\*\//s', '', $hero);

        foreach (['linear-gradient', 'radial-gradient', 'text-shadow', 'backdrop-filter', 'scrim'] as $cheat) {
            $this->assertStringNotContainsString($cheat, $declarations, "the hero must not use {$cheat}");
        }

        // And no partial alpha on the plate itself.
        $this->assertDoesNotMatchRegularExpression('/\.hero__plate \{[^}]*opacity:/s', $declarations);
    }

    /**
     * The statement is drawn beneath the wordmark. Layering is a paint-order
     * decision and must not become a reading-order one: the sentence has to be
     * in the markup, in the right place, and not hidden from assistive tech.
     */
    public function test_the_statement_is_reachable_and_correctly_ordered(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $statement = \App\Support\Settings::get('site.statement');
        $this->assertNotNull($statement);

        $hero = substr($html, (int) strpos($html, 'class="hero'), 6000);

        // It is really there, as text, not as a background or an image.
        $this->assertStringContainsString(e($statement), $hero);

        // Never hidden from the accessibility tree to tidy up the visuals.
        $this->assertDoesNotMatchRegularExpression(
            '/<p class="hero__statement"[^>]*aria-hidden/',
            $hero
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<div class="hero__voice"[^>]*(aria-hidden|hidden)/',
            $hero
        );

        /*
         * Reading order: the heading, then the frame's data, then the
         * statement, then the attribution, then the one link, then the
         * project's metadata. That is DOM order, which is what a screen reader
         * and the tab key both follow — the visual layering below it is
         * z-index only and changes neither.
         */
        $positions = [
            'h1' => strpos($hero, '<h1'),
            'technical' => strpos($hero, 'hero__technical'),
            'statement' => strpos($hero, 'hero__statement'),
            'attribution' => strpos($hero, 'hero__attribution'),
            'link' => strpos($hero, 'hero__link'),
            'stages' => strpos($hero, 'hero__stages'),
        ];

        foreach ($positions as $name => $at) {
            $this->assertIsInt($at, "{$name} is missing from the hero");
        }

        $order = array_values($positions);
        $sorted = $order;
        sort($sorted);

        $this->assertSame($sorted, $order, 'the hero reads in a different order than it is written: '.json_encode($positions));

        // And the effect really is paint order, not a hidden duplicate.
        $css = file_get_contents(resource_path('css/pages/home.css'));
        $this->assertMatchesRegularExpression(
            '/\.hero__voice \{[^}]*z-index: var\(--z-beneath\)/s',
            $css
        );
        $this->assertSame(1, substr_count($hero, e($statement)), 'the statement appears exactly once');
    }

    /**
     * The hero renders the client's words, not the developer's.
     */
    public function test_the_hero_holds_no_hardcoded_copy(): void
    {
        $views = [
            resource_path('views/components/home/hero.blade.php'),
            resource_path('views/components/home/client-marquee.blade.php'),
            resource_path('views/layouts/public.blade.php'),
        ];

        /*
         * Every seeded value, plus the reference's own vocabulary. A literal
         * hit means a string a client cannot change without a deploy.
         */
        $forbidden = \App\Models\Setting::query()->pluck('value')->filter()->all();

        foreach ($views as $view) {
            $source = (string) file_get_contents($view);

            // Comments explain the design; they are not rendered.
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            foreach ($forbidden as $value) {
                $this->assertStringNotContainsString(
                    $value,
                    $source,
                    basename($view).' hardcodes a setting: '.$value
                );
            }
        }

        // And the rendered page really does contain them, from the table.
        $this->populate();
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['site.name', 'site.statement', 'nav.work', 'stage.1.label'] as $key) {
            $this->assertStringContainsString(
                e((string) \App\Support\Settings::get($key)),
                $html,
                "{$key} did not reach the page"
            );
        }

        // Change the table, change the page. No deploy, no cache clear.
        \App\Support\Settings::set('stage.1.label', 'Latent', 'string', 'hero');

        $this->assertStringContainsString('Latent', $this->get('/')->getContent());
    }

    /**
     * No text column may sit in the plate's columns, and it may not abut them
     * either.
     *
     * The Task 18 report claimed the statement is "never under the plate".
     * Measured, that was true and irrelevant: with a portrait frame the text
     * column ended at exactly 607px and the plate began at exactly 607px, so
     * the last letter of the project link sat flush against a hard
     * photographic edge and read as sliced in half. "Does not overlap" is not
     * the requirement; "is visibly separate from" is.
     */
    public function test_no_hero_text_shares_a_column_with_the_plate(): void
    {
        $css = file_get_contents(resource_path('css/pages/home.css'));

        $span = static function (string $pattern) use ($css): array {
            preg_match($pattern, $css, $found);

            self::assertNotEmpty($found, 'no rule matched '.$pattern);

            $start = (int) $found[1];
            // "a / span b" and "a / -1" both appear in this stylesheet.
            $end = str_contains($found[2], 'span')
                ? $start + (int) filter_var($found[2], FILTER_SANITIZE_NUMBER_INT) - 1
                : 12;

            return [$start, $end];
        };

        $orientations = [
            'landscape' => [
                'plate' => '/\.hero--landscape \.hero__plate \{ grid-column: (\d+) \/ ([^;]+); \}/',
                'text' => '/\.hero--landscape \.hero__aside \{\s*grid-column: (\d+) \/ ([^;]+);/',
            ],
            'portrait' => [
                'plate' => '/\.hero--portrait\s+\.hero__plate \{ grid-column: (\d+) \/ ([^;]+); \}/',
                'text' => '/\.hero--portrait \.hero__aside \{\s*grid-column: (\d+) \/ ([^;]+);/',
            ],
            'square' => [
                'plate' => '/\.hero--square\s+\.hero__plate \{ grid-column: (\d+) \/ ([^;]+); \}/',
                'text' => '/\.hero--square \.hero__aside \{\s*grid-column: (\d+) \/ ([^;]+);/',
            ],
        ];

        foreach ($orientations as $name => $patterns) {
            [$plateStart, $plateEnd] = $span($patterns['plate']);
            [$textStart, $textEnd] = $span($patterns['text']);

            $overlap = min($textEnd, $plateEnd) - max($textStart, $plateStart) + 1;

            $this->assertLessThanOrEqual(
                0,
                $overlap,
                sprintf(
                    '%s: text occupies columns %d-%d and the plate %d-%d, overlapping by %d',
                    $name, $textStart, $textEnd, $plateStart, $plateEnd, $overlap
                )
            );
        }

        /*
         * And a declared gap on the side that faces the picture, in all three.
         * Adjacency is what actually looked broken.
         */
        $this->assertMatchesRegularExpression(
            '/\.hero__voice,\s*\.hero__aside \{ --hero-plate-gap: var\(--sp-\d+\); \}/',
            $css,
            'no clearance is declared between the text column and the plate'
        );

        /*
         * ───────────────────────────────────────────────────────────────────
         * REWRITTEN IN TASK 33 D3. IT USED TO ASSERT THE DEFECT.
         *
         * This loop required padding-left for landscape and square and
         * padding-right for portrait — the PHYSICAL properties, named
         * literally. Those are the sides that face the picture in English and
         * the sides that face AWAY from it in Arabic, because the grid
         * columns flip on the inline axis and physical padding does not.
         *
         * So the Arabic home page put the clearance on the wrong edge and the
         * hero text abutted a hard photographic edge at exactly zero pixels —
         * the thing the comment in home.css was written to prevent — while
         * this test confirmed everything was correct.
         *
         * The assertion is now the one that was meant all along: the gap is on
         * the side facing the picture WHICHEVER WAY THE PAGE RUNS. That can
         * only be said with a logical property, so a physical one on these
         * selectors is now a failure rather than a requirement.
         * ───────────────────────────────────────────────────────────────────
         */
        foreach ([
            'landscape' => 'padding-inline-start',
            'square' => 'padding-inline-start',
            'portrait' => 'padding-inline-end',
        ] as $name => $side) {
            $this->assertMatchesRegularExpression(
                '/\.hero--'.$name.' \.hero__aside \{[^}]*'.$side.': var\(--hero-plate-gap\)/s',
                $css,
                "{$name}: the text column has no clearance from the plate on the inline axis"
            );
        }

        foreach (['landscape', 'square', 'portrait'] as $name) {
            preg_match('/\.hero--'.$name.' \.hero__voice,\s*\.hero--'.$name.' \.hero__aside \{([^}]*)\}/s', $css, $rule);

            $this->assertDoesNotMatchRegularExpression(
                '/padding-(left|right):/',
                $rule[1] ?? '',
                "{$name}: the clearance is physical, so it is on the wrong side in Arabic"
            );
        }
    }

    public function test_the_statement_is_cut_by_the_wordmark_but_not_swallowed(): void
    {
        $css = file_get_contents(resource_path('css/pages/home.css'));

        preg_match('/\.hero__voice \{[^}]*margin-top: calc\(var\(--wordmark-size\) \* (-[\d.]+)\)/s', $css, $found);

        $this->assertNotEmpty($found, 'the statement declares no overlap with the wordmark');

        $factor = (float) $found[1];

        /*
         * The band this has to sit in, established by measuring rather than by
         * looking — twice, in both directions:
         *
         *   -0.42  two lines of three swallowed
         *   -0.34  the statement's whole height inside the wordmark's band
         *   -0.13  no visual overlap at all: the wordmark's BOX is 20px taller
         *          than its glyphs, so box arithmetic reads an overlap the eye
         *          cannot see
         *
         * Between -0.18 and -0.30 the first line is crossed by the letters'
         * feet and the rest hangs clear.
         */
        $this->assertGreaterThanOrEqual(-0.30, $factor, 'the wordmark swallows the sentence');
        $this->assertLessThanOrEqual(-0.18, $factor, 'the wordmark no longer crosses the sentence at all');
    }

    public function test_the_query_count_is_flat_and_the_settings_map_is_one_query(): void
    {
        $this->populate(8);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();

        /*
         * Sixteen on a cold cache, twelve on a warm one.
         *
         * THE THREE THAT DISAPPEAR are the site-wide maps, each read once per
         * process and then cached forever:
         *
         *   - the SETTINGS map: one flat `select value, key from settings`
         *     carrying every string the hero renders — the studio name, the
         *     statement, the attribution, five navigation labels, four stage
         *     labels and four rail labels;
         *   - the REDIRECT map, which is why a 404 costs no query (Task 25);
         *   - the footer's LEGAL LINKS, which is why the footer can ask
         *     whether a privacy notice exists without asking on every page.
         *
         * Asserting BOTH numbers is the point. A bare "14" would pass just as
         * happily if the settings map were being read once per key, which is
         * the failure this design exists to avoid — hence the separate
         * assertion that there is exactly one settings query.
         *
         * RAISED IN TASK 24 for the journal strip, and again in TASK 25 for
         * the services strip: neither articles nor services can ride along on
         * a query about projects, and each is one flat select.
         *
         * RAISED AGAIN IN TASK 34 D1, by one, for the NAVIGATION.
         *
         * The header's links used to be six entries hardcoded in the layout,
         * which is why they cost nothing: nobody could change them without a
         * deploy. They are rows now, so the owner can reorder them, switch
         * one off, or add a link to a page they have just written — and that
         * costs one `select * from menus where location = ?` on a cold cache.
         *
         * ONE, not one per link: App\Support\Navigation resolves the whole
         * menu and caches it per locale, exactly like the legal links above.
         * The fixture here has an empty menu, so the items query does not
         * appear; NavigationTest measures the populated case and holds it
         * flat as links are added.
         *
         * RAISED AGAIN IN TASK 34 D2, by one on BOTH counts, for the
         * testimonial strip.
         *
         * Deliberately NOT cached, unlike the navigation: a testimonial has
         * a publish date, and a cache that outlives it would hold back a
         * quote the studio had scheduled. One flat select with its portraits
         * eager-loaded is the right price for that.
         */
        $settingsQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '`settings`'));

        $this->assertCount(1, $settingsQueries, 'the settings map must be one query, not one per key');
        $this->assertCount(16, $queries, 'cold: '.count($queries).' queries:'.PHP_EOL.implode(PHP_EOL, $queries));

        // Warm, which is every request after the first.
        $queries = [];
        $this->get('/')->assertOk();

        $this->assertCount(12, $queries, 'warm: '.count($queries).' queries:'.PHP_EOL.implode(PHP_EOL, $queries));
    }

    /**
     * The vertical variant must be gone, not dormant. A commented-out block or
     * a stray class name is how a replaced design comes back six months later
     * as a half-working branch of CSS nobody dares touch.
     */
    public function test_no_trace_of_the_replaced_variant_remains(): void
    {
        $roots = ['app', 'config', 'resources', 'routes', 'tests', 'database'];
        $extensions = ['php', 'css', 'js', 'json'];

        $needles = [
            'hero-b',              // the old component and class prefix
            'hero_variant',        // the switch that chose between them
            'HERO_VARIANT',
            'heroVariants',        // the parameterised provider
            'hero__column',        // the vertical variant's single text column
            'hero__stage-rule-b',
        ];

        $hits = [];

        foreach ($roots as $root) {
            $directory = new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS);

            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if (! in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }

                // This file names the forbidden strings in order to look for
                // them, so it cannot also be a subject of the search.
                if ($file->getPathname() === __FILE__) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                foreach ($needles as $needle) {
                    if (str_contains($source, $needle)) {
                        $hits[] = str_replace(base_path().'/', '', $file->getPathname()).' → '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $hits, "the replaced variant is still referenced:\n".implode("\n", $hits));

        $this->assertFileDoesNotExist(resource_path('views/components/home/hero-b.blade.php'));
        $this->assertFileDoesNotExist(config_path('site.php'));

        // The surviving hero is the horizontal one.
        $hero = (string) file_get_contents(resource_path('views/components/home/hero.blade.php'));
        $this->assertStringContainsString('hero__rail', $hero, 'the restored hero has the metadata rail');
        $this->assertStringContainsString('hero__aside', $hero);
    }

    public function test_the_hero_preview_route_does_not_exist_outside_local(): void
    {
        /*
         * The suite runs with APP_ENV=testing, which is not local — so the
         * whole guarded block is skipped and the route is never registered.
         * That is the assertion worth making: not that it exists here, but
         * that it does not exist anywhere that is not a developer's machine.
         */
        $this->assertFalse(app()->environment('local'), 'the suite runs as testing');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('dev.hero'));

        $this->get('/_dev/hero')->assertNotFound();

        // And it is inside the guard in the source, not merely absent by luck.
        $source = (string) file_get_contents(base_path('routes/web.php'));
        $guardAt = strpos($source, "app()->environment('local')");

        $this->assertIsInt($guardAt);

        foreach (["Route::get('/_dev/hero'", "Route::get('/_dev/media'"] as $route) {
            $at = strpos($source, $route);
            $this->assertIsInt($at, $route.' is missing');
            $this->assertGreaterThan($guardAt, $at, $route.' must sit inside the local-only guard');
        }
    }

    public function test_the_marquee_is_static_under_reduced_motion(): void
    {
        \App\Models\Client::factory()->count(3)->create();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('class="marquee"', $html);

        $css = file_get_contents(resource_path('css/pages/home.css'));

        // Not merely paused: the whole conceit is abandoned and the names wrap.
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{.*?\.marquee__viewport \{[^}]*animation: none/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{.*?\.marquee__track \{[^}]*flex-wrap: wrap/s',
            $css
        );
        // The duplicate track is removed once nothing moves.
        $this->assertMatchesRegularExpression(
            '/\.marquee__track\[aria-hidden=.true.\] \{ display: none; \}/',
            $css
        );
    }

    public function test_the_marquee_duplicate_is_hidden_from_assistive_tech_and_the_tab_order(): void
    {
        \App\Models\Client::factory()->create(['name' => 'Meridian Press', 'url' => 'https://example.com/m']);

        $html = $this->get('/')->assertOk()->getContent();

        // The name appears twice visually, but only once to a screen reader.
        $this->assertSame(2, substr_count($html, 'Meridian Press'));
        $this->assertStringContainsString('aria-hidden="true"', $html);
        // <main> carries tabindex="-1" too, so count within the marquee only.
        $marquee = substr($html, (int) strpos($html, 'class="marquee"'));
        $marquee = substr($marquee, 0, (int) strpos($marquee, '</section>'));

        $this->assertSame(1, substr_count($marquee, 'tabindex="-1"'), 'the duplicate link is out of the tab order');
    }

    public function test_an_inactive_client_never_appears(): void
    {
        \App\Models\Client::factory()->create(['name' => 'Shown Client']);
        \App\Models\Client::factory()->inactive()->create(['name' => 'Hidden Client']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Shown Client', $html);
        $this->assertStringNotContainsString('Hidden Client', $html);
    }

    public function test_no_client_name_is_hardcoded_in_any_view(): void
    {
        $views = array_merge(
            glob(resource_path('views/components/home/*.blade.php')) ?: [],
            [resource_path('views/home.blade.php')]
        );

        foreach ($views as $view) {
            $source = file_get_contents($view);

            foreach (['Aperture Quarterly', 'Meridian Press', 'Nord & Vale', 'Studio Halcyon'] as $name) {
                $this->assertStringNotContainsString($name, $source, basename($view));
            }
        }
    }

    /**
     * Task 38 Part C — the home page alternates registers down its length.
     *
     * Asserted on the rendered DOM rather than the template, because the
     * thing that went wrong the first time was not in the template: with no
     * testimonials, two fixed-position bands met and read as one block. So
     * this checks the property itself — after the marquee, the sections that
     * render strictly alternate page / band, no two bands touch, and no band
     * is empty — with the full set of content and with a section missing.
     */
    public function test_the_sections_alternate_registers_down_the_page(): void
    {
        $this->populate();

        $this->assertAlternates($this->mainChildren($this->get('/')->assertOk()->getContent()), 'with every section');

        // Without testimonials — the case that broke fixed positions.
        \App\Models\Testimonial::query()->delete();

        $this->assertAlternates($this->mainChildren($this->get('/')->assertOk()->getContent()), 'without testimonials');
    }

    public function test_an_empty_site_draws_no_bands(): void
    {
        foreach ($this->mainChildren($this->get('/')->assertOk()->getContent()) as $node) {
            if (str_contains(' '.$node->getAttribute('class').' ', ' register ')) {
                $this->assertNotSame('', trim($node->textContent), 'an empty register band rendered on an empty site');
            }
        }
    }

    /** @return list<\DOMElement> */
    private function mainChildren(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $main = $doc->getElementById('main');
        $this->assertNotNull($main);

        $out = [];
        foreach ($main->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @param list<\DOMElement> $nodes */
    private function assertAlternates(array $nodes, string $case): void
    {
        $isBand = fn (\DOMElement $n) => str_contains(' '.$n->getAttribute('class').' ', ' register ');

        // Everything after the opening — the hero, and the marquee when there
        // are clients to show — takes part in the alternation.
        $start = null;
        foreach ($nodes as $i => $n) {
            if (preg_match('/(^| )(hero|marquee)( |$)/', $n->getAttribute('class'))) {
                $start = $i + 1;
            }
        }
        $this->assertNotNull($start, "{$case}: the hero did not render, so the alternation has no anchor");

        $sequence = array_map($isBand, array_slice($nodes, $start));
        $this->assertGreaterThanOrEqual(4, count($sequence), "{$case}: too few sections rendered to alternate");

        foreach ($sequence as $i => $band) {
            $this->assertSame($i % 2 === 0, $band, "{$case}: section ".($i + 1).' after the marquee is in the wrong register');
        }

        foreach (array_slice($nodes, $start) as $n) {
            if ($isBand($n)) {
                $this->assertNotSame('', trim($n->textContent).$n->getElementsByTagName('img')->length, "{$case}: an empty band rendered");
            }
        }
    }

    /**
     * Task 38 E2 — the numbered grid's rows are the services records. The
     * numerals follow the record count: five services read 01-05, never
     * padded to six, and more than six stops at six.
     */
    public function test_the_numbered_grid_scales_with_the_services(): void
    {
        \App\Models\Service::factory()->count(5)->sequence(fn ($s) => ['sort_order' => $s->index])->create();

        $html = (string) $this->get('/')->assertOk()->getContent();
        preg_match_all('#<span class="services-strip__number" aria-hidden="true">(\d+)</span>#', $html, $m);
        $this->assertSame(['01', '02', '03', '04', '05'], $m[1]);

        \App\Models\Service::factory()->count(3)->create();
        \Illuminate\Support\Facades\Cache::flush();

        preg_match_all('#<span class="services-strip__number" aria-hidden="true">(\d+)</span>#', (string) $this->get('/')->getContent(), $m);
        $this->assertSame(['01', '02', '03', '04', '05', '06'], $m[1]);
    }
}
