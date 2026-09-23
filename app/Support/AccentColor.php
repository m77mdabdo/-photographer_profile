<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Takes a colour sampled from a photograph and makes it usable as an accent.
 *
 * A dominant colour is whatever the image happened to contain: a muddy brown,
 * a near-black, a blown-out white. None of those are legible as a link colour,
 * and simply using them would hand the page a contrast failure that varies
 * with whichever photograph is featured that week.
 *
 * So the hue is kept — that is the part carrying the relationship to the
 * image — and the lightness is walked until the result clears the contrast
 * target against the ground it will actually sit on.
 */
final class AccentColor
{
    /** WCAG AA for normal text. */
    public const TARGET = 4.5;

    /**
     * The usable lightness band per ground, in HSL terms.
     *
     * Contrast alone is not enough. #181010 clears 4.5:1 on paper at 17:1 and
     * is completely useless as an accent — it is indistinguishable from body
     * text, so it signals nothing. An accent has to be legible AND visibly a
     * colour, which means it must sit away from both the ground and the text.
     *
     * On a dark ground the text is near-white, so the band sits below it:
     * bright enough to read, never so bright it becomes another white.
     * On paper the text is near-black, so the band sits above it.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const BANDS = [
        'dark' => [0.45, 0.76],
        'light' => [0.28, 0.56],
    ];

    /**
     * How far the lightness may travel from the source before the result stops
     * being a colour taken from that photograph and becomes an invention.
     * Beyond this the caller gets null and uses the token accent, which is
     * honest, rather than a technically-valid colour with no relationship to
     * the image.
     */
    private const MAX_LIGHTNESS_SHIFT = 0.45;

    /**
     * @return string|null  A hex colour, or null when nothing usable was given
     *                      or the source cannot be made into a usable accent.
     */
    public static function derive(?string $source, string $background, float $target = self::TARGET): ?string
    {
        $rgb = self::parse($source);
        $backgroundRgb = self::parse($background);

        if ($rgb === null || $backgroundRgb === null) {
            return null;
        }

        [$h, $s, $l] = self::toHsl($rgb);

        /*
         * A colour with almost no saturation carries no hue worth preserving.
         * Give it a floor so the result still reads as a colour rather than as
         * another shade of the text.
         */
        $s = max($s, 0.22);

        $backgroundLuminance = self::luminance($backgroundRgb);
        $onDark = $backgroundLuminance < 0.5;
        [$bandLow, $bandHigh] = self::BANDS[$onDark ? 'dark' : 'light'];

        /*
         * Walk the band from the end nearest the source, so the result stays
         * as close to the photograph's own lightness as the constraints allow.
         */
        $candidates = [];

        for ($step = 0; $step <= 100; $step++) {
            $candidate = $bandLow + (($bandHigh - $bandLow) * ($step / 100));
            $candidates[] = $candidate;
        }

        usort($candidates, static fn (float $a, float $b): int => abs($a - $l) <=> abs($b - $l));

        foreach ($candidates as $candidateLightness) {
            if (abs($candidateLightness - $l) > self::MAX_LIGHTNESS_SHIFT) {
                continue;
            }

            $rgbCandidate = self::fromHsl($h, $s, $candidateLightness);

            if (self::contrast(self::luminance($rgbCandidate), $backgroundLuminance) >= $target) {
                return self::toHex($rgbCandidate);
            }
        }

        // Nothing in the band both clears the target and still resembles the
        // source. The caller falls back to the token accent.
        return null;
    }

    /**
     * The HSL lightness of a colour, for asserting the band in tests.
     */
    /**
     * The furthest this class will move a colour's lightness before deciding
     * the result has stopped being the photograph's colour. Exposed so the
     * palette selector can prefer sources that need less of that journey.
     */
    public static function maxLightnessShift(): float
    {
        return self::MAX_LIGHTNESS_SHIFT;
    }

    public static function lightnessOf(string $colour): ?float
    {
        $rgb = self::parse($colour);

        return $rgb === null ? null : self::toHsl($rgb)[2];
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function bandFor(string $background): array
    {
        $rgb = self::parse($background);
        $onDark = $rgb !== null && self::luminance($rgb) < 0.5;

        return self::BANDS[$onDark ? 'dark' : 'light'];
    }

    public static function contrastAgainst(string $colour, string $background): float
    {
        $a = self::parse($colour);
        $b = self::parse($background);

        if ($a === null || $b === null) {
            return 0.0;
        }

        return self::contrast(self::luminance($a), self::luminance($b));
    }

    // ----------------------------------------------------------------- maths

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function luminance(array $rgb): float
    {
        $channels = array_map(static function (int $value): float {
            $v = $value / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(float $a, float $b): float
    {
        [$high, $low] = $a > $b ? [$a, $b] : [$b, $a];

        return ($high + 0.05) / ($low + 0.05);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parse(?string $colour): ?array
    {
        if ($colour === null) {
            return null;
        }

        $hex = ltrim(trim($colour), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toHsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $rgb);

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d === 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h / 6, $s, $l];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function fromHsl(float $h, float $s, float $l): array
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);

            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - ($l * $s);
        $p = (2 * $l) - $q;

        return [
            (int) round(self::hueToChannel($p, $q, $h + 1 / 3) * 255),
            (int) round(self::hueToChannel($p, $q, $h) * 255),
            (int) round(self::hueToChannel($p, $q, $h - 1 / 3) * 255),
        ];
    }

    private static function hueToChannel(float $p, float $q, float $t): float
    {
        $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

        return match (true) {
            $t < 1 / 6 => $p + (($q - $p) * 6 * $t),
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + (($q - $p) * (2 / 3 - $t) * 6),
            default => $p,
        };
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(
            static fn (int $v): int => max(0, min(255, $v)),
            $rgb
        ));
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
