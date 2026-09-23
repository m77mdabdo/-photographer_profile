<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;

/**
 * Scores a photograph against what the interlocked hero actually needs.
 *
 * Choosing a hero image by eye is how a page ends up working for exactly one
 * picture. These three measurements are the ones that decide whether the
 * composition holds:
 *
 *   LUMINANCE   the mean brightness of the frame, 0 to 1. The plate is a
 *               hard-edged rectangle on a near-black ground, so a pale frame
 *               reads as a white box stuck onto the page. Low key is under
 *               about 0.30.
 *   SATURATION  the HSL saturation of the stored dominant colour. A grey frame
 *               has nothing to give the accent.
 *   ACCENT      whether AccentColor can actually derive a usable colour in
 *               both registers, or refuses and falls back to the token. This
 *               is the one that matters most, because it is pass or fail
 *               rather than a matter of degree.
 */
final readonly class HeroSuitability
{
    public const LOW_KEY_CEILING = 0.30;

    public const MIN_SATURATION = 0.15;

    private function __construct(
        public int $mediaId,
        public ?float $luminance,
        public ?float $saturation,
        public ?string $dominant,
        public ?string $accentDark,
        public ?string $accentLight,
    ) {}

    public static function for(Media $media, string $darkGround = '#0b0b0c', string $lightGround = '#f4f1ea'): self
    {
        return new self(
            mediaId: $media->id,
            luminance: self::meanLuminance($media),
            saturation: self::saturationOf($media->dominant_color),
            dominant: $media->dominant_color,
            accentDark: AccentColor::derive($media->dominant_color, $darkGround),
            accentLight: AccentColor::derive($media->dominant_color, $lightGround),
        );
    }

    public function isLowKey(): bool
    {
        return $this->luminance !== null && $this->luminance <= self::LOW_KEY_CEILING;
    }

    public function accentHolds(): bool
    {
        return $this->accentDark !== null && $this->accentLight !== null;
    }

    public function hasColour(): bool
    {
        return $this->saturation !== null && $this->saturation >= self::MIN_SATURATION;
    }

    /**
     * A single number so a library can be ordered.
     *
     * The accent is pass/fail and dominates deliberately: a frame that cannot
     * colour the page has failed at the one job the hero asks of it, however
     * beautifully dark it happens to be.
     */
    public function score(): float
    {
        if ($this->luminance === null) {
            return 0.0;
        }

        // 1.0 at pure black, 0 at the ceiling and below.
        $darkness = max(0.0, (self::LOW_KEY_CEILING - $this->luminance) / self::LOW_KEY_CEILING);

        $colour = min(1.0, ($this->saturation ?? 0.0) / 0.45);

        return round(($this->accentHolds() ? 1.0 : 0.0) * 0.5 + $darkness * 0.3 + $colour * 0.2, 4);
    }

    /**
     * Mean luminance of the smallest rendition, which is a faithful enough
     * average of the frame and costs a fraction of the decode.
     */
    private static function meanLuminance(Media $media): ?float
    {
        $variant = $media->variants
            ->where('format', 'jpeg')
            ->sortBy('width')
            ->first()
            ?? $media->variants->sortBy('width')->first();

        if ($variant === null) {
            return null;
        }

        $path = \Illuminate\Support\Facades\Storage::disk($variant->disk)->path($variant->path);

        if (! is_file($path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            return null;
        }

        // A 32x32 thumbnail is plenty for an average and bounds the work.
        $small = imagescale($image, 32, 32);

        if ($small === false) {
            return null;
        }

        $total = 0.0;

        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $rgb = imagecolorat($small, $x, $y);

                $total += AccentColor::luminance([
                    ($rgb >> 16) & 0xFF,
                    ($rgb >> 8) & 0xFF,
                    $rgb & 0xFF,
                ]);
            }
        }

        return round($total / 1024, 4);
    }

    private static function saturationOf(?string $hex): ?float
    {
        $rgb = AccentColor::parse($hex);

        if ($rgb === null) {
            return null;
        }

        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, $rgb);

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        if ($max === $min) {
            return 0.0;   // pure grey
        }

        $l = ($max + $min) / 2;

        return round(($max - $min) / ($l > 0.5 ? (2.0 - $max - $min) : ($max + $min)), 4);
    }
}
