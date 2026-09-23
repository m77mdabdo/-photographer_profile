<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Support\AccentColor;
use App\Support\AccentPalette;
use App\Support\PaletteExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixture;
use Tests\TestCase;

class AccentPaletteTest extends TestCase
{
    use RefreshDatabase;

    private const DARK = '#0b0b0c';

    private const LIGHT = '#f4f1ea';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();
    }

    /** @return list<array{color: string, share: float}> */
    private function paletteOf(string $bytes): array
    {
        $path = ImageFixture::toFile($bytes);

        try {
            return PaletteExtractor::fromPath($path);
        } finally {
            unlink($path);
        }
    }

    // ----------------------------------------------------------------- 1

    public function test_the_palette_matches_a_known_distribution(): void
    {
        // Exactly half charcoal, half red.
        $palette = $this->paletteOf(ImageFixture::halfAndHalf('#101012', '#c81e1e'));

        $this->assertGreaterThanOrEqual(2, count($palette));

        $shares = array_column($palette, 'share', 'color');

        // Both halves are present, each at about 50%, ordered largest first.
        $this->assertEqualsWithDelta(0.5, $palette[0]['share'], 0.02);
        $this->assertEqualsWithDelta(0.5, $palette[1]['share'], 0.02);
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.01, 'the shares cover the frame');

        // And each entry really is the colour that was painted, not the centre
        // of the bucket it landed in.
        $colours = array_keys($shares);

        $this->assertTrue(
            $this->near($colours[0], '#101012') || $this->near($colours[0], '#c81e1e'),
            'first entry is one of the two painted colours, got '.$colours[0]
        );
        $this->assertTrue(
            $this->near($colours[1], '#101012') || $this->near($colours[1], '#c81e1e'),
            'second entry is the other, got '.$colours[1]
        );
        $this->assertNotSame($colours[0], $colours[1]);
    }

    public function test_the_palette_never_exceeds_its_entry_limit(): void
    {
        $palette = $this->paletteOf(ImageFixture::monochrome());

        $this->assertLessThanOrEqual(PaletteExtractor::MAX_ENTRIES, count($palette));
    }

    // ----------------------------------------------------------------- 2

    /**
     * The whole argument, in one test.
     *
     * A frame that is 97% near-black with a 3% crimson block in it. Averaging
     * gives near-black — that is what dominant_color did, and it is why four
     * art-directed images came back at zero saturation. Salience must give the
     * crimson.
     */
    public function test_a_small_saturated_region_beats_the_dark_background(): void
    {
        $bytes = ImageFixture::lowKeyWithAccent('#c81e46', 0.03);
        $palette = $this->paletteOf($bytes);

        // The background really does dominate by area ...
        $this->assertGreaterThan(0.9, $palette[0]['share'], 'the background is the largest region');
        $this->assertTrue($this->near($palette[0]['color'], '#0c0c0d'), 'and it is the near-black');

        // ... and the accent is taken from the crimson anyway.
        $selected = AccentPalette::select($palette);

        $this->assertNotNull($selected, 'a saturated region must be selectable');
        $this->assertTrue(
            $this->near($selected, '#c81e46', 12),
            "expected the crimson region, got {$selected}"
        );

        /*
         * And the comparison that matters: what the old signal would have
         * produced from the same frame. A one-pixel average of this image is
         * near-black, and near-black cannot be brought to the dark register's
         * lightness band without travelling further than AccentColor permits.
         */
        $averaged = $this->averageOf($bytes);

        $this->assertTrue($this->near($averaged, '#0c0c0d', 24), "the average is near-black, got {$averaged}");
        $this->assertNull(
            AccentColor::derive($averaged, self::DARK),
            'the old signal yields no usable accent for this frame'
        );
        $this->assertNotNull(
            AccentColor::derive($selected, self::DARK),
            'the new signal does'
        );
    }

    public function test_a_stray_speck_cannot_colour_the_page(): void
    {
        // Half a per cent — below MIN_SHARE, however vivid it is.
        $palette = $this->paletteOf(ImageFixture::lowKeyWithAccent('#ff0066', 0.005));

        $this->assertNull(
            AccentPalette::select($palette),
            'a region under '.(AccentPalette::MIN_SHARE * 100).'% of the frame must not win'
        );
    }

    // ----------------------------------------------------------------- 3

    public function test_a_monochrome_frame_yields_the_token_accent(): void
    {
        $palette = $this->paletteOf(ImageFixture::monochrome());

        // Every entry is a true grey: r, g and b equal.
        foreach ($palette as $entry) {
            $rgb = AccentColor::parse($entry['color']);
            $this->assertNotNull($rgb);
            $this->assertSame(
                0,
                max($rgb) - min($rgb),
                $entry['color'].' should be a pure grey'
            );
        }

        $this->assertNull(AccentPalette::select($palette), 'there is no hue to take');
    }

    public function test_noise_in_the_blacks_is_not_mistaken_for_colour(): void
    {
        /*
         * #020101 reports an HSL saturation of 0.33 from a single unit of
         * channel difference. An earlier version of the selector scored it
         * highly and an image that had previously derived a usable brown went
         * backwards to a fallback.
         */
        $palette = $this->paletteOf(ImageFixture::nearBlackNoise());

        $this->assertNotEmpty($palette);
        $this->assertNull(
            AccentPalette::select($palette),
            'quantisation noise below lightness '.AccentPalette::MIN_LIGHTNESS.' is not a colour'
        );
    }

    public function test_the_media_model_falls_back_to_the_token_accent(): void
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'dominant_color' => '#3a3a3a',
            'palette' => $this->paletteOf(ImageFixture::monochrome()),
            'palette_read_at' => now(),
        ]);

        /*
         * Null, not the dominant colour. Once the palette has been read its
         * answer is final — falling through would hand a neutral average to
         * AccentColor, whose saturation floor would invent a hue from it.
         */
        $this->assertNull($media->accentSource());
        $this->assertNull(AccentColor::derive($media->accentSource(), self::DARK));
    }

    public function test_a_row_with_no_palette_still_uses_the_old_signal(): void
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'dominant_color' => '#5a4a38',
            'palette' => null,
            'palette_read_at' => null,
        ]);

        $this->assertSame('#5a4a38', $media->accentSource());
    }

    // ----------------------------------------------------------------- 4

    public function test_a_selected_colour_still_clears_the_floor_and_the_band(): void
    {
        $sources = [
            ImageFixture::lowKeyWithAccent('#c81e46', 0.04),
            ImageFixture::lowKeyWithAccent('#1e6fc8', 0.06),
            ImageFixture::lowKeyWithAccent('#c8a01e', 0.10),
            ImageFixture::halfAndHalf('#101012', '#2e7d5b'),
        ];

        foreach ($sources as $index => $bytes) {
            $selected = AccentPalette::select($this->paletteOf($bytes));

            $this->assertNotNull($selected, "fixture {$index} yielded no colour");

            foreach (['dark' => self::DARK, 'light' => self::LIGHT] as $mode => $ground) {
                $derived = AccentColor::derive($selected, $ground);

                $this->assertNotNull($derived, "fixture {$index}/{$mode}: {$selected} produced nothing");

                [$low, $high] = AccentColor::bandFor($ground);
                $lightness = AccentColor::lightnessOf($derived);

                $this->assertNotNull($lightness);
                $this->assertGreaterThanOrEqual($low, $lightness, "fixture {$index}/{$mode}: below the band");
                $this->assertLessThanOrEqual($high, $lightness, "fixture {$index}/{$mode}: above the band");

                $this->assertGreaterThanOrEqual(
                    AccentColor::TARGET,
                    AccentColor::contrastAgainst($derived, $ground),
                    "fixture {$index}/{$mode}: below the contrast floor"
                );
            }
        }
    }

    public function test_the_selector_prefers_salience_over_area(): void
    {
        /*
         * Stated as a property rather than as a single case: given a large
         * neutral region and a smaller saturated one that both clear the
         * gates, the saturated one wins at every share the smaller can take.
         */
        foreach ([0.02, 0.05, 0.12, 0.25, 0.40] as $share) {
            $palette = [
                ['color' => '#3b3b3d', 'share' => 1.0 - $share],   // large, neutral
                ['color' => '#b8442f', 'share' => $share],          // smaller, saturated
            ];

            $this->assertSame(
                '#b8442f',
                AccentPalette::select($palette),
                "area {$share} should not have beaten salience"
            );
        }
    }

    private function near(string $a, string $b, int $tolerance = 8): bool
    {
        $x = AccentColor::parse($a);
        $y = AccentColor::parse($b);

        if ($x === null || $y === null) {
            return false;
        }

        foreach ([0, 1, 2] as $channel) {
            if (abs($x[$channel] - $y[$channel]) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    /** The old signal: the whole frame resized to one pixel. */
    private function averageOf(string $bytes): string
    {
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image);

        $one = imagescale($image, 1, 1);
        $this->assertNotFalse($one);

        $rgb = imagecolorat($one, 0, 0);

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }
}
