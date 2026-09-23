<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Support\SeoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);
    }

    /**
     * Render just the head fragment, so assertions are not fighting the layout.
     */
    private function seoHead(?SeoData $page = null): string
    {
        return Blade::render('<x-seo :page="$page" />', ['page' => $page]);
    }

    private function readyMediaWithJpeg(): Media
    {
        $media = Media::factory()->create([
            'width' => 4000,
            'height' => 2667,
            'status' => MediaStatus::Ready,
            'alt' => 'A dune at first light',
        ]);

        $path = sprintf('2026/09/%s/1920.jpg', $media->uuid);
        Storage::disk('media_public')->put($path, 'x');

        $media->variants()->create([
            'format' => 'jpeg',
            'width' => 1920,
            'height' => 1280,
            'disk' => 'media_public',
            'path' => $path,
            'size_bytes' => 4242,
        ]);

        return $media->refresh()->load('variants');
    }

    // -----------------------------------------------------------------

    public function test_the_home_page_title_contains_the_site_name(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<title>'.config('seo.site_name').'</title>', false);
    }

    public function test_the_canonical_is_absolute_and_drops_the_query_string(): void
    {
        $response = $this->get('/?utm_source=newsletter&page=2');

        $response->assertOk();
        $response->assertSee('<link rel="canonical" href="'.config('app.url').'">', false);

        /*
         * Scoped to the canonical and og:url tags rather than the whole page.
         * The theme switch carries the current request URI in a hidden field
         * so it can return the visitor to where they were, so the query string
         * does legitimately appear in the body — escaped, and nowhere that
         * affects indexing.
         */
        preg_match_all('/<link rel="canonical"[^>]*>|<meta property="og:url"[^>]*>/', $response->getContent(), $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertStringNotContainsString('utm_source', $tag);
            $this->assertStringNotContainsString('page=2', $tag);
        }
    }

    public function test_robots_defaults_to_index_follow(): void
    {
        $this->get('/')->assertSee('<meta name="robots" content="index, follow">', false);
    }

    public function test_a_page_can_override_the_title_description_and_robots(): void
    {
        // Appended form: "{page title} — {site name}".
        $appended = $this->seoHead(SeoData::make(
            title: 'Archive',
            description: 'Every frame, in order.',
            robots: 'noindex, nofollow',
        ));

        $this->assertStringContainsString(
            '<title>Archive — '.config('seo.site_name').'</title>',
            $appended
        );
        $this->assertStringContainsString('content="Every frame, in order."', $appended);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $appended);

        // Whole-title override: the site name is not appended.
        $raw = $this->seoHead(SeoData::make(title: 'A Complete Title', rawTitle: true));

        $this->assertStringContainsString('<title>A Complete Title</title>', $raw);
        $this->assertStringNotContainsString('A Complete Title — ', $raw);
    }

    public function test_og_image_tags_are_omitted_entirely_without_an_image(): void
    {
        $html = $this->seoHead(SeoData::make(title: 'No picture'));

        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('twitter:image', $html);

        // And nothing is emitted as an empty tag.
        $this->assertStringNotContainsString('content=""', $html);

        // Without an image the card degrades to a summary.
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
    }

    public function test_og_image_outputs_width_and_height_when_media_is_given(): void
    {
        $media = $this->readyMediaWithJpeg();

        $html = $this->seoHead(SeoData::make(title: 'With picture', ogImage: $media));

        $expectedUrl = 'http://localhost/storage/media/2026/09/'.$media->uuid.'/1920.jpg';

        $this->assertStringContainsString('<meta property="og:image" content="'.$expectedUrl.'">', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1920">', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="1280">', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="A dune at first light">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="'.$expectedUrl.'">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function test_the_404_page_returns_404_and_is_noindex(): void
    {
        $response = $this->get('/a-page-that-does-not-exist');

        $response->assertStatus(404);
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $response->assertSee('Nothing here', false);
    }

    public function test_every_error_view_renders_without_throwing(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = view("errors.{$code}")->render();

            $this->assertStringContainsString('Error '.$code, $html, "errors.{$code} body");
            $this->assertStringContainsString('noindex, nofollow', $html, "errors.{$code} robots");
            $this->assertStringContainsString('</html>', $html, "errors.{$code} layout");
        }
    }

    public function test_the_layout_renders_its_accessibility_scaffolding(): void
    {
        $response = $this->get('/');

        $response->assertSee('<a class="skip-link" href="#main">Skip to content</a>', false);
        $response->assertSee('<main id="main"', false);
        $response->assertSee('aria-label="Primary"', false);
        $response->assertSee('lang="en"', false);
        $response->assertSee('dir="ltr"', false);
    }

    public function test_no_empty_meta_tags_are_ever_emitted(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('content=""', false);
    }
}
