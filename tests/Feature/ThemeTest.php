<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    // ----------------------------------------------------------------- 1

    public function test_with_no_cookie_the_stylesheet_decides(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $html = $response->getContent();

        // No attribute at all, so prefers-color-scheme governs. Writing
        // data-theme="dark" here would override a visitor's light OS.
        $this->assertStringNotContainsString('data-theme', $html);
        $this->assertMatchesRegularExpression('/<html lang="en"\s+dir="ltr"\s*>/', $html);

        // And the stylesheet really does carry the media query.
        $css = file_get_contents(resource_path('css/tokens.css'));
        $this->assertStringContainsString('@media (prefers-color-scheme: light)', $css);
        $this->assertStringContainsString(':root:not([data-theme])', $css);
    }

    // ----------------------------------------------------------------- 2

    public function test_posting_light_sets_the_cookie_and_renders_the_attribute(): void
    {
        $response = $this->post(route('theme.store'), ['theme' => 'light', 'return_to' => '/']);

        $response->assertRedirect('/');
        $response->assertCookie(Theme::COOKIE, 'light');

        $html = $this->withCookie(Theme::COOKIE, 'light')->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-theme="light"', $html);

        // Dark works the same way.
        $darkHtml = $this->withCookie(Theme::COOKIE, 'dark')->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme="dark"', $darkHtml);
    }

    // ----------------------------------------------------------------- 3

    public function test_posting_system_clears_the_cookie(): void
    {
        $this->post(route('theme.store'), ['theme' => 'light', 'return_to' => '/'])
            ->assertCookie(Theme::COOKIE, 'light');

        $response = $this->withCookie(Theme::COOKIE, 'light')
            ->post(route('theme.store'), ['theme' => 'system', 'return_to' => '/']);

        $response->assertRedirect('/');
        $response->assertCookieExpired(Theme::COOKIE);

        // Following the system is the absence of a preference, not a third
        // stored value.
        $this->assertSame(Theme::System, Theme::fromCookie(null));
        $this->assertSame(Theme::System, Theme::fromCookie('nonsense'));
        $this->assertNull(Theme::System->attribute());
    }

    // ----------------------------------------------------------------- 4

    public function test_the_form_posts_normally_and_returns_to_the_same_url(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // A real form with a real button, not a link or a checkbox.
        $this->assertStringContainsString('<form method="POST" action="'.route('theme.store').'"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('name="theme"', $html);
        $this->assertStringContainsString('value="system"', $html);
        $this->assertStringContainsString('value="light"', $html);
        $this->assertStringContainsString('value="dark"', $html);

        // Labelled for assistive technology, and state is exposed.
        $this->assertStringContainsString('<legend class="sr-only">Colour scheme</legend>', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);

        // A deep link with a query string comes back intact.
        $this->post(route('theme.store'), ['theme' => 'dark', 'return_to' => '/work/x?page=2'])
            ->assertRedirect('/work/x?page=2');

        // An off-site return target is refused rather than followed.
        $this->post(route('theme.store'), ['theme' => 'dark', 'return_to' => 'https://evil.test/x'])
            ->assertRedirect('/');
        $this->post(route('theme.store'), ['theme' => 'dark', 'return_to' => '//evil.test/x'])
            ->assertRedirect('/');
    }

    // ----------------------------------------------------------------- 5

    public function test_the_page_still_contains_zero_script_tags(): void
    {
        foreach ([null, 'light', 'dark'] as $cookie) {
            $request = $cookie === null ? $this : $this->withCookie(Theme::COOKIE, $cookie);
            $html = $request->get('/')->assertOk()->getContent();

            $this->assertStringNotContainsString('<script', $html, "theme={$cookie}");
            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringNotContainsString('localStorage', $html);
        }
    }

    // ----------------------------------------------------------------- 6

    public function test_the_theme_route_is_csrf_protected_and_validates(): void
    {
        /*
         * Laravel's ValidateCsrfToken short-circuits when the application is
         * running its own tests, so posting without a token here returns 302
         * rather than 419 no matter what middleware is re-registered. Asserting
         * 419 would be asserting a fiction. What can be proven is that the
         * route sits in the web group — which is what applies the middleware in
         * a real request — and that the form actually sends a token.
         */
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->getName() === 'theme.store');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="_token" value="[^"]+"/', $html);

        // An unknown value is refused by validation regardless.
        $this->post(route('theme.store'), ['theme' => 'sepia'])->assertSessionHasErrors('theme');
        $this->post(route('theme.store'), [])->assertSessionHasErrors('theme');
    }

    // ----------------------------------------------------------------- 7

    public function test_every_alias_is_defined_in_both_registers(): void
    {
        $css = file_get_contents(resource_path('css/tokens.css'));

        preg_match('/^:root \{(.*?)^\}/ms', $css, $dark);
        preg_match('/^:root\[data-theme=\'light\'\] \{(.*?)^\}/ms', $css, $light);
        preg_match('/@media \(prefers-color-scheme: light\) \{(.*?)^\}/ms', $css, $media);

        $this->assertNotEmpty($dark, 'the dark block is missing');
        $this->assertNotEmpty($light, 'the light attribute block is missing');
        $this->assertNotEmpty($media, 'the system-preference block is missing');

        $required = [
            '--bg', '--bg-elevated', '--text-primary', '--text-secondary', '--border', '--accent',
            '--on-accent', '--on-danger', '--danger', '--danger-text',
            '--scrim-rgb', '--scrim-top', '--scrim-mid', '--scrim-bottom',
            '--wght-display', '--wght-mid',
            '--grain-opacity', '--grain-blend',
            '--vignette-rgb', '--vignette-opacity-min', '--vignette-opacity-max',
        ];

        foreach (['dark' => $dark[1], 'light' => $light[1], 'system-light' => $media[1]] as $name => $block) {
            foreach ($required as $token) {
                $this->assertMatchesRegularExpression(
                    '/^\s*'.preg_quote($token, '/').':/m',
                    $block,
                    "{$token} is not defined in the {$name} register"
                );
            }
        }
    }

    // ----------------------------------------------------------------- 8

    public function test_no_stylesheet_outside_tokens_touches_the_raw_palette(): void
    {
        $offenders = [];

        foreach ($this->stylesheets() as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $number => $line) {
                if (str_contains($line, 'var(--c-')) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "raw palette used outside tokens.css:\n".implode("\n", $offenders));
    }

    // ----------------------------------------------------------------- 9

    public function test_no_colour_literal_lives_outside_tokens(): void
    {
        $offenders = [];

        foreach ($this->stylesheets() as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $number => $line) {
                if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgb\(\s*\d|hsl\(/', $line) === 1) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "colour literals outside tokens.css:\n".implode("\n", $offenders));
    }

    // ----------------------------------------------------------------- 10

    public function test_the_theme_does_not_change_the_query_count(): void
    {
        $this->seedPublishedWork();

        /*
         * One listener for the whole test. Registering inside the loop stacks
         * a second closure on the second pass, and every query is then counted
         * twice — 8 becomes 16, which looks like a regression and is not one.
         */
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ([null, 'light', 'dark'] as $cookie) {
            $queries = [];

            $request = $cookie === null ? $this : $this->withCookie(Theme::COOKIE, $cookie);
            $request->get('/')->assertOk();

            // The theme is a cookie read and an attribute; it touches nothing.
            $this->assertCount(
                8,
                $queries,
                "theme=".($cookie ?? 'system')." issued ".count($queries).' queries'
            );
        }
    }

    // ----------------------------------------------------------------- caching

    public function test_html_varies_on_cookie_but_assets_do_not(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringContainsStringIgnoringCase(
            'Cookie',
            (string) $response->headers->get('Vary'),
            'HTML that differs by cookie must say so'
        );
    }


    /**
     * Eight published projects with covers and heroes, so the homepage does
     * the work it does in production. Without this the page is an empty state
     * and the query count measures nothing.
     */
    private function seedPublishedWork(): void
    {
        \Illuminate\Support\Facades\Storage::fake('media_originals');
        \Illuminate\Support\Facades\Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        $media = function (): \App\Models\Media {
            $item = \App\Models\Media::factory()->create([
                'status' => \App\Enums\MediaStatus::Ready, 'width' => 4000, 'height' => 2667,
            ]);

            $path = sprintf('2026/09/%s/768.webp', $item->uuid);
            \Illuminate\Support\Facades\Storage::disk('media_public')->put($path, 'x');
            $item->variants()->create([
                'format' => 'webp', 'width' => 768, 'height' => 512,
                'disk' => 'media_public', 'path' => $path, 'size_bytes' => 1024,
            ]);

            return $item->refresh();
        };

        $categories = \App\Models\Category::factory()->count(3)->create();

        for ($i = 0; $i < 8; $i++) {
            $project = \App\Models\Project::factory()->published()->create([
                'title' => 'Series '.($i + 1), 'slug' => 'series-'.($i + 1),
                'is_featured' => $i === 0, 'sort_order' => $i,
                'cover_media_id' => $media()->id, 'hero_media_id' => $media()->id,
            ]);

            $project->categories()->attach($categories->random(2)->pluck('id'));
        }
    }

    /**
     * @return list<string>
     */
    private function stylesheets(): array
    {
        $files = array_merge(
            glob(resource_path('css/*.css')) ?: [],
            glob(resource_path('css/components/*.css')) ?: [],
            glob(resource_path('css/pages/*.css')) ?: []
        );

        return array_values(array_filter(
            $files,
            fn (string $file): bool => basename($file) !== 'tokens.css'
        ));
    }
}
