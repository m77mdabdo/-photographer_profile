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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);
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

        [$fw, $fh] = $featuredShape === 'portrait' ? [2667, 4000] : [4000, 2667];

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

    public function test_the_hero_image_is_never_lazy_and_is_high_priority(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $hero = substr($html, (int) strpos($html, 'class="hero"'), 4000);

        // The LCP element: high priority, decoded synchronously, never lazy.
        $this->assertStringContainsString('fetchpriority="high"', $hero);
        $this->assertStringContainsString('decoding="sync"', $hero);

        $heroImg = substr($hero, (int) strpos($hero, '<img'), (int) strpos($hero, '>', (int) strpos($hero, '<img')));
        $this->assertStringNotContainsString('loading="lazy"', $heroImg);

        // Below the fold, lazy loading is still in force.
        $this->assertStringContainsString('loading="lazy"', $html);
    }

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

    public function test_the_query_count_is_bounded(): void
    {
        $this->populate(8);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();

        $count = count($queries);

        $this->assertLessThan(
            10,
            $count,
            "expected a flat homepage, got {$count}:\n".implode("\n", $queries)
        );
    }

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

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('livewire', strtolower($html));
        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    // ================= TASK 11 =================

    // ----------------------------------------------------------------- 4

    public function test_the_display_font_is_preloaded_and_the_sans_is_not(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        // The display face sets the largest text on the page, so its swap
        // would be the most visible; it earns the early request.
        $this->assertStringContainsString(
            '<link rel="preload" href="/fonts/bodoni-moda-latin.woff2" as="font" type="font/woff2" crossorigin>',
            $html
        );

        // The sans must not compete with it, or with the hero image.
        $this->assertStringNotContainsString('inter-tight', $html);
        // @vite emits its own stylesheet preload, so count font preloads only.
        $this->assertSame(1, substr_count($html, 'as="font"'), 'exactly one font is preloaded');

        // Nothing is fetched from a third party.
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);

        // And the files are actually self-hosted.
        foreach (['bodoni-moda-latin', 'bodoni-moda-latin-ext',
                  'inter-tight-latin', 'inter-tight-latin-ext'] as $file) {
            $this->assertFileExists(public_path("fonts/{$file}.woff2"));
        }
    }

    public function test_the_font_faces_declare_metric_matched_fallbacks(): void
    {
        $css = file_get_contents(resource_path('css/fonts.css'));

        // Without these the swap reflows every line of the largest text.
        $this->assertStringContainsString('size-adjust', $css);
        $this->assertStringContainsString('ascent-override', $css);
        $this->assertStringContainsString('descent-override', $css);
        $this->assertStringContainsString('font-display: swap', $css);

        $this->assertStringContainsString("font-family: 'Bodoni Fallback'", $css);
        $this->assertStringContainsString("font-family: 'Inter Fallback'", $css);

        // The tokens are the only place the families are named.
        $tokens = file_get_contents(resource_path('css/tokens.css'));
        $this->assertStringContainsString("--font-display: 'Bodoni Moda'", $tokens);
        $this->assertStringContainsString("--font-sans: 'Inter Tight'", $tokens);
        $this->assertStringContainsString('Bodoni Fallback', $tokens);
    }

    // ----------------------------------------------------------------- 5

    public function test_the_page_still_contains_no_script_tags(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script', $html);
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

    public function test_the_query_count_is_nine_with_the_marquee(): void
    {
        $this->populate(8);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();

        /*
         * Nine, not eight. The client marquee is a ninth query and there is no
         * way around it — the clients table is unrelated to projects, so it
         * cannot ride along on an existing load. The layout planner still
         * costs nothing: it reads covers that are already in memory.
         */
        $this->assertCount(9, $queries, "expected 9 queries:\n".implode("\n", $queries));
    }

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

        preg_match_all('/^\s*([^{}]+)\{[^}]*var\(--accent\)[^}]*\}/m', $css, $matches);

        $selectors = array_map('trim', $matches[1]);

        /*
         * Only primary-action rules may use it — plus the hero's own fallback
         * chain, where `var(--accent)` is the default the derived colour falls
         * back to rather than a second decorative use.
         */
        foreach ($selectors as $selector) {
            $clean = trim(preg_replace('/\/\*.*?\*\//s', '', $selector) ?? '');

            // At-rule wrappers are not selectors; the rules inside them are
            // matched on their own.
            if ($clean === '' || str_starts_with($clean, '@')
                || str_contains($clean, '.hero') || str_contains($clean, ':root')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/link-rule|closing__rule/',
                $clean,
                "the accent leaked into: {$clean}"
            );
        }

        // Specifically, it was removed from the index numerals and eyebrows.
        $this->assertMatchesRegularExpression('/\.work__index \{[^}]*--text-secondary/s', $css);
        $this->assertMatchesRegularExpression('/\.story__eyebrow \{ color: var\(--text-secondary\)/', $css);
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

    // ================= TASK 14 =================

    public function test_the_wordmark_is_one_intact_text_node(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();
        $name = config('seo.site_name');

        // One h1, containing the studio name whole — not split across spans,
        // not an image, and not repeated anywhere else as a heading.
        $this->assertSame(1, substr_count($html, '<h1'), 'exactly one h1');
        $this->assertMatchesRegularExpression(
            '/<h1 class="hero__wordmark" id="hero-title">'.preg_quote($name, '/').'<\/h1>/',
            $html,
            'the wordmark must be a single text node'
        );

        // Nothing fragmenting it.
        $this->assertStringNotContainsString('wordmark__letter', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*<span/', $html);
    }

    public function test_the_hero_accent_is_derived_from_the_image_and_falls_back(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        $expectedDark = \App\Support\AccentColor::derive('#5a4a38', '#0b0b0c');
        $expectedLight = \App\Support\AccentColor::derive('#5a4a38', '#f4f1ea');

        $this->assertStringContainsString('--hero-accent-dark: '.$expectedDark, $html);
        $this->assertStringContainsString('--hero-accent-light: '.$expectedLight, $html);

        // With no usable colour, neither property is emitted and the CSS
        // fallback to the token accent applies.
        Media::query()->update(['dominant_color' => null]);

        $plain = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('--hero-accent-dark', $plain);
        $this->assertStringNotContainsString('--hero-accent-light', $plain);
        $this->assertStringContainsString('hero__wordmark', $plain, 'the hero still renders');
    }

    public function test_a_portrait_featured_image_produces_a_different_column(): void
    {
        $this->populate(4, 'landscape');
        $landscape = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('hero--landscape', $landscape);
        $this->assertStringNotContainsString('hero--portrait', $landscape);

        // A fresh set, featured on a portrait frame.
        // forceDelete: Project soft-deletes, and the slug unique index still
        // sees a trashed row.
        Project::withTrashed()->get()->each->forceDelete();
        Media::withTrashed()->get()->each->forceDelete();
        $this->populate(4, 'portrait');

        $portrait = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('hero--portrait', $portrait);
        $this->assertStringNotContainsString('hero--landscape', $portrait);

        // And the stylesheet gives those two classes different columns.
        $css = file_get_contents(resource_path('css/pages/home.css'));
        $this->assertMatchesRegularExpression('/\.hero--landscape \.hero__plate \{ grid-column: 2 \/ span 8; \}/', $css);
        $this->assertMatchesRegularExpression('/\.hero--portrait\s+\.hero__plate \{ grid-column: 6 \/ span 5; \}/', $css);
    }

    public function test_the_hero_renders_with_no_featured_project_at_all(): void
    {
        // No projects, no media: the wordmark must still carry the screen.
        $response = $this->get('/');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('hero--empty', $html);
        $this->assertStringContainsString('<h1 class="hero__wordmark"', $html);
        $this->assertStringNotContainsString('hero__plate', $html);
        $this->assertStringNotContainsString('hero__aside', $html);
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
}
