<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The colours a photograph is made of, and how much of it each one covers.
 *
 * WHY THIS EXISTS
 *
 * The pipeline already stores dominant_color, sampled by resizing the frame to
 * a single pixel. That answers "what is the average of everything", which for
 * a low-key photograph is the background and nothing else — four of nine
 * art-directed images came back #060606, #090909 or #0e0e0e at zero
 * saturation, and there was no colour in them to find because averaging had
 * already destroyed it.
 *
 * A palette keeps the regions separate. A blindfold covering three per cent of
 * a frame survives here as its own entry with its own share, which is what
 * makes it selectable later; in an average it is three per cent of a nudge
 * toward red and then it is gone.
 *
 * This does not replace dominant_color. That value has other uses — the
 * placeholder tint behind a loading image wants the average, precisely
 * because the average is what the eye expects to resolve into the picture.
 */
final class PaletteExtractor
{
    /**
     * The frame is reduced to this before counting. 64x64 is 4096 samples:
     * enough that a two per cent region is eighty pixels rather than a
     * rounding error, and small enough that the whole thing is a few
     * milliseconds.
     */
    public const SAMPLE = 64;

    /**
     * Channel bits kept when bucketing. Three bits each gives 8x8x8 = 512
     * cells, so two colours land together only if they are within about a
     * thirty-second of the range on every channel — close enough to be one
     * region to a viewer, far enough apart that a red scarf and an orange
     * wall stay separate entries.
     */
    private const BITS = 3;

    public const MAX_ENTRIES = 8;

    /**
     * @return list<array{color: string, share: float}> ordered by share, largest first
     */
    public static function fromPath(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return [];
        }

        return self::fromImage($image);
    }

    /**
     * @param  \GdImage  $image
     * @return list<array{color: string, share: float}>
     */
    public static function fromImage($image): array
    {
        $small = imagescale($image, self::SAMPLE, self::SAMPLE);

        if ($small === false) {
            return [];
        }

        $shift = 8 - self::BITS;

        /** @var array<int, array{r: int, g: int, b: int, n: int}> $cells */
        $cells = [];

        for ($y = 0; $y < self::SAMPLE; $y++) {
            for ($x = 0; $x < self::SAMPLE; $x++) {
                $rgb = imagecolorat($small, $x, $y);

                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $key = (($r >> $shift) << (self::BITS * 2))
                    | (($g >> $shift) << self::BITS)
                    | ($b >> $shift);

                if (! isset($cells[$key])) {
                    $cells[$key] = ['r' => 0, 'g' => 0, 'b' => 0, 'n' => 0];
                }

                // The cell's colour is the mean of what fell in it, not the
                // centre of the bucket: quantising is for grouping, not for
                // deciding what the group looks like.
                $cells[$key]['r'] += $r;
                $cells[$key]['g'] += $g;
                $cells[$key]['b'] += $b;
                $cells[$key]['n']++;
            }
        }

        if ($cells === []) {
            return [];
        }

        uasort($cells, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        $total = self::SAMPLE * self::SAMPLE;
        $palette = [];

        foreach (array_slice($cells, 0, self::MAX_ENTRIES, true) as $cell) {
            $palette[] = [
                'color' => sprintf(
                    '#%02x%02x%02x',
                    (int) round($cell['r'] / $cell['n']),
                    (int) round($cell['g'] / $cell['n']),
                    (int) round($cell['b'] / $cell['n']),
                ),
                'share' => round($cell['n'] / $total, 4),
            ];
        }

        return $palette;
    }
}
