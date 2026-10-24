<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Chooses which colour in a photograph should become the page's accent.
 *
 * THE QUESTION THIS ANSWERS
 *
 * Not "which colour covers the most of the frame" — that is the background,
 * and in a low-key photograph the background is near-black. The question is
 * "which colour draws the eye", which is a different measurement: a small,
 * strongly saturated region beats a large neutral one, because that is how
 * looking works.
 *
 * THE WEIGHTING
 *
 *   saturation   0.62   the signal itself. A colour that is barely a colour
 *                       cannot be an accent however much of the frame it
 *                       covers, so this carries most of the weight.
 *   presence     0.18   proof the region is real rather than noise or a
 *                       compression artefact. It SATURATES at 15% — beyond
 *                       that, more area earns nothing. This is deliberately
 *                       not "bigger is better": that is the rule that broke.
 *   band fit     0.20   how close the colour already sits to the lightness
 *                       AccentColor will need it at. A colour that is already
 *                       there survives derivation unchanged and still looks
 *                       like the photograph; one that has to travel 0.4 of a
 *                       lightness arrives as an invention.
 *
 * Two hard gates come before the score, because a weighted sum will always
 * find a winner and sometimes there should not be one:
 *
 *   - MIN_SHARE stops a stray pixel from colouring the page.
 *   - MIN_SATURATION stops a grey from being dressed up as a hue. When no
 *     entry clears it the answer is null, the caller falls back to the token
 *     accent, and that is a correct answer rather than a failure.
 */
final class AccentPalette
{
    /** Below this a region is noise, not a subject. 1.5% of 4096 samples is 61 pixels. */
    public const MIN_SHARE = 0.015;

    /** Below this there is no hue to take, only a grey with a rounding error in it. */
    public const MIN_SATURATION = 0.18;

    /**
     * Saturation is only meaningful away from the extremes of lightness.
     *
     * #020101 reports a saturation of 0.33 — from a two-unit difference
     * between channels, which is quantisation noise in a black frame. The
     * first version of this class had only a soft discount for that, and a
     * near-black entry with a large share still beat the real colours: one
     * image that had derived a usable brown from its average went BACKWARDS to
     * a fallback. A measurement that cannot be trusted has to be excluded, not
     * weighted down.
     *
     * At a lightness of 0.10 a saturation of 0.5 still means a separation of
     * about twenty-five levels, which is a colour. Below it, it is not.
     */
    public const MIN_LIGHTNESS = 0.10;

    public const MAX_LIGHTNESS = 0.92;

    /** More area than this earns nothing further. */
    private const PRESENCE_CEILING = 0.15;

    /**
     * The lightness both registers can reach cheaply.
     *
     * AccentColor's bands are 0.45–0.76 on a dark ground and 0.28–0.56 on
     * paper. Their overlap is 0.45–0.56, so a source that starts inside it
     * serves dark and light with the least movement in either.
     */
    private const TARGET_LOW = 0.45;

    private const TARGET_HIGH = 0.56;

    private const W_SATURATION = 0.62;

    private const W_PRESENCE = 0.18;

    private const W_BAND = 0.20;

    /**
     * @param  list<array{color: string, share: float|int}>|null  $palette
     */
    public static function select(?array $palette): ?string
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($palette ?? [] as $entry) {
            $colour = $entry['color'] ?? null;
            $share = (float) ($entry['share'] ?? 0);

            if (! is_string($colour) || $share < self::MIN_SHARE) {
                continue;
            }

            $hsl = self::toHsl($colour);

            if ($hsl === null) {
                continue;
            }

            [, $saturation, $lightness] = $hsl;

            if ($saturation < self::MIN_SATURATION) {
                continue;
            }

            if ($lightness < self::MIN_LIGHTNESS || $lightness > self::MAX_LIGHTNESS) {
                continue;   // its saturation figure is noise, not a hue
            }

            /*
             * A colour at the extremes of lightness has a saturation figure
             * that does not mean much — near-black and near-white both report
             * high saturation for a few units of channel difference. Discount
             * it rather than gate on it, so a genuinely deep colour is still
             * eligible but a compression speck is not.
             */
            $credible = 1.0 - (abs($lightness - 0.5) * 1.6);
            $credible = max(0.0, min(1.0, $credible));

            $score = self::W_SATURATION * $saturation * $credible
                + self::W_PRESENCE * min(1.0, $share / self::PRESENCE_CEILING)
                + self::W_BAND * self::bandFit($lightness);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $colour;
            }
        }

        return $best;
    }

    /**
     * 1.0 inside the shared band, falling to 0 at the furthest distance
     * AccentColor is willing to move a colour.
     */
    private static function bandFit(float $lightness): float
    {
        if ($lightness >= self::TARGET_LOW && $lightness <= self::TARGET_HIGH) {
            return 1.0;
        }

        $distance = $lightness < self::TARGET_LOW
            ? self::TARGET_LOW - $lightness
            : $lightness - self::TARGET_HIGH;

        return max(0.0, 1.0 - ($distance / AccentColor::maxLightnessShift()));
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null hue, saturation, lightness
     */
    private static function toHsl(string $colour): ?array
    {
        $rgb = AccentColor::parse($colour);

        if ($rgb === null) {
            return null;
        }

        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, $rgb);

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $delta = $max - $min;
        $s = $l > 0.5 ? $delta / (2.0 - $max - $min) : $delta / ($max + $min);

        $h = match (true) {
            $max === $r => fmod((($g - $b) / $delta) + ($g < $b ? 6.0 : 0.0), 6.0),
            $max === $g => (($b - $r) / $delta) + 2.0,
            default => (($r - $g) / $delta) + 4.0,
        } / 6.0;

        return [$h, $s, $l];
    }
}
