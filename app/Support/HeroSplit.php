<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where the plate crosses an Arabic wordmark — Task 38 Part A, second pass.
 *
 * The English hero's plate crosses the wordmark mid-word: Latin letters are
 * unjoined, so every visible fragment is still a letterform. The Arabic hero
 * keeps that composition and moves the cut to the one place a cursive script
 * allows it, the space between two words, so each word stands whole on its
 * own side of the photograph.
 *
 * THE PLATE'S PLACE COMES FROM THE NAME, and the name is a settings value, so
 * nothing here knows what it is. The plate keeps the span its photograph's
 * orientation gives it, exactly as in English; what is derived is where that
 * span starts. The columns it leaves free are shared between the two sides in
 * proportion to the two words' SET WIDTHS (WordmarkMetrics, read from the
 * font) — to a fraction of a column, because seven free columns cannot be
 * shared evenly between two words of equal width, and rounding to four and
 * three capped the type at the narrow side. pages/home.css then sets the
 * largest size at which each word fits its own side.
 *
 * ONE WORD HAS NO GAP, and three or more words have several, which a single
 * justified line cannot weight unequally while staying one text node. Both
 * return null and the hero keeps the vertical interlock (.hero--vertical).
 */
final class HeroSplit
{
    /** Columns the plate spans, per photograph orientation — as in English. */
    private const SPAN = ['landscape' => 8, 'portrait' => 5, 'square' => 6];

    /**
     * @return array<string, int|float>|null the hero's custom properties, or
     *                                       null when the name has no single
     *                                       boundary to cut at
     */
    public static function for(string $name, string $orientation): ?array
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) !== 2) {
            return null;
        }

        $span = self::SPAN[$orientation] ?? self::SPAN['landscape'];
        $free = 12 - $span;

        $first = WordmarkMetrics::width($words[0]);
        $second = WordmarkMetrics::width($words[1]);

        // At least one whole column either side, however unequal the words.
        $start = round($free * $first / ($first + $second), 3);
        $start = max(1.0, $start);
        $start = $start > $free - 1.0 ? $free - 1.0 : $start;

        return [
            'plate-span' => $span,
            'side-start' => $start,
            'side-end' => round($free - $start, 3),
            'word-start' => round($first, 3),
            'word-end' => round($second, 3),
            // The statement and the link sit beyond the plate, in the whole
            // columns left after it.
            'voice-start' => (int) ceil($start + $span) + 1,
        ];
    }
}
