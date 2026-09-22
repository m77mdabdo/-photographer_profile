<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PictureComponentTest extends TestCase
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
     * A ready media row with real variant rows, without running the encoder.
     *
     * @param  array<string, list<int>>  $formats
     */
    private function mediaWithVariants(
        array $formats = ['avif' => [480, 1080, 1920], 'webp' => [480, 1080, 1920], 'jpeg' => [1080, 1920]],
        array $attributes = []
    ): Media {
        $media = Media::factory()->create(array_merge([
            'width' => 4000,
            'height' => 2667,
            'status' => MediaStatus::Ready,
            'lqip' => 'data:image/webp;base64,UklGRhoAAABXRUJQ',
            'dominant_color' => '#7e807e',
        ], $attributes));

        $extensions = ['avif' => 'avif', 'webp' => 'webp', 'jpeg' => 'jpg'];

        foreach ($formats as $format => $widths) {
            foreach ($widths as $width) {
                $path = sprintf('2026/09/%s/%d.%s', $media->uuid, $width, $extensions[$format]);

                Storage::disk('media_public')->put($path, 'x');

                $media->variants()->create([
                    'format' => $format,
                    'width' => $width,
                    'height' => (int) round($width * 2667 / 4000),
                    'disk' => 'media_public',
                    'path' => $path,
                    'size_bytes' => 1234,
                ]);
            }
        }

        return $media->refresh()->load('variants');
    }

    private function render(Media $media, string $expression = ''): string
    {
        return Blade::render(
            '<x-picture :media="$media" '.$expression.' />',
            ['media' => $media]
        );
    }

    // -----------------------------------------------------------------

    public function test_it_renders_avif_then_webp_then_the_img(): void
    {
        $html = $this->render($this->mediaWithVariants());

        $this->assertStringContainsString('<source type="image/avif"', $html);
        $this->assertStringContainsString('<source type="image/webp"', $html);
        $this->assertStringContainsString('<img', $html);

        $avif = strpos($html, 'type="image/avif"');
        $webp = strpos($html, 'type="image/webp"');
        $img = strpos($html, '<img');

        $this->assertIsInt($avif);
        $this->assertIsInt($webp);
        $this->assertIsInt($img);

        // Source order is how the browser chooses: first match wins.
        $this->assertLessThan($webp, $avif, 'avif must precede webp');
        $this->assertLessThan($img, $webp, 'webp must precede the img');

        $this->assertSame(2, substr_count($html, '<source'));
        $this->assertSame(1, substr_count($html, '<img'));
    }

    public function test_width_and_height_match_the_media_row(): void
    {
        $media = $this->mediaWithVariants();

        $html = $this->render($media);

        $this->assertStringContainsString('width="4000"', $html);
        $this->assertStringContainsString('height="2667"', $html);
        $this->assertSame(4000, $media->width);
        $this->assertSame(2667, $media->height);

        // The intrinsic ratio is what reserves layout space before paint.
        $this->assertStringContainsString('aspect-ratio: 4000 / 2667', $html);
    }

    public function test_priority_sets_high_fetchpriority_and_omits_lazy_loading(): void
    {
        $html = $this->render($this->mediaWithVariants(), ':priority="true"');

        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringContainsString('decoding="sync"', $html);

        // Not merely "not lazy" — the attribute must be absent entirely.
        $this->assertStringNotContainsString('loading=', $html);
    }

    public function test_the_default_is_lazy_loaded(): void
    {
        $html = $this->render($this->mediaWithVariants());

        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('fetchpriority="auto"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
    }

    public function test_decorative_forces_an_empty_alt_and_ignores_the_media_alt(): void
    {
        $media = $this->mediaWithVariants(attributes: ['alt' => 'A dune at first light']);

        $decorative = $this->render($media, ':decorative="true"');

        $this->assertStringContainsString('alt=""', $decorative);
        $this->assertStringNotContainsString('A dune at first light', $decorative);

        // Without the flag the media's own alt is used.
        $normal = $this->render($media);
        $this->assertStringContainsString('alt="A dune at first light"', $normal);
    }

    public function test_a_pending_media_renders_a_placeholder_with_no_img(): void
    {
        $media = $this->mediaWithVariants();
        $media->update(['status' => MediaStatus::Pending]);
        $media->refresh()->load('variants');

        $html = $this->render($media);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<picture', $html);
        $this->assertStringContainsString('data-media-placeholder="pending"', $html);

        // It still reserves the space and shows the sampled colour.
        $this->assertStringContainsString('aspect-ratio: 4000 / 2667', $html);
        $this->assertStringContainsString('background-color: #7e807e', $html);
    }

    public function test_media_without_jpeg_falls_back_to_the_widest_variant(): void
    {
        $media = $this->mediaWithVariants([
            'avif' => [480, 1080, 1920],
            'webp' => [480, 1080, 1920],
        ]);

        $html = $this->render($media);

        $this->assertFalse($media->hasFormat('jpeg'));
        $this->assertStringContainsString('<img', $html);

        // The widest rendition of any format becomes the src.
        $this->assertStringContainsString('src="http://localhost/storage/media/2026/09/'.$media->uuid.'/1920.webp"', $html);

        // No jpeg srcset is emitted when there are no jpeg variants.
        $this->assertSame(2, substr_count($html, '<source'));
        $this->assertStringNotContainsString('.jpg', $html);
    }

    public function test_sizes_is_applied_to_every_source_and_to_the_img(): void
    {
        $html = $this->render($this->mediaWithVariants(), 'sizes="(max-width: 600px) 100vw, 50vw"');

        // Two sources plus the img.
        $this->assertSame(3, substr_count($html, 'sizes="(max-width: 600px) 100vw, 50vw"'));
    }

    public function test_the_markup_leaks_no_doubled_segment_and_no_original_filename(): void
    {
        $media = $this->mediaWithVariants(attributes: [
            'original_filename' => 'Desert Editorial FINAL v2.jpg',
        ]);

        $html = $this->render($media);

        $this->assertStringNotContainsString('media/media', $html);
        $this->assertStringNotContainsString('Desert', $html);
        $this->assertStringNotContainsString('Editorial', $html);
        $this->assertStringNotContainsString('FINAL', $html);
        $this->assertStringContainsString($media->uuid, $html);
    }

    public function test_extra_attributes_are_merged_onto_the_img(): void
    {
        $html = $this->render($this->mediaWithVariants(), 'id="hero" data-test="x"');

        $this->assertStringContainsString('id="hero"', $html);
        $this->assertStringContainsString('data-test="x"', $html);

        // Merged onto the img, not the picture wrapper.
        $imgTag = substr($html, (int) strpos($html, '<img'));
        $this->assertStringContainsString('id="hero"', $imgTag);
    }

    public function test_a_null_media_renders_a_placeholder_rather_than_failing(): void
    {
        $html = Blade::render('<x-picture />');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('data-media-placeholder="missing"', $html);
        $this->assertStringContainsString('aspect-ratio: 3 / 2', $html);
    }
}
