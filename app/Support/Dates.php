<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Dates in the reader's language, with Western numerals.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * format() IS LOCALE-BLIND, AND THAT IS THE BUG THIS CLASS FIXES.
 *
 * Seven public views called ->format('j F Y'), which asks PHP for the month
 * name and PHP always answers in English. So an Arabic journal entry was dated
 * "14 November 2026" — the only English word left on the page once everything
 * else had been translated, and the one the Part 3d scan caught last.
 *
 * isoFormat() asks CARBON, which does translate — but only if its locale has
 * been set, and Laravel does not set it from the app locale on its own. Doing
 * that at each of seven call sites is how one of them ends up forgotten.
 *
 * NOT A SPECULATIVE ABSTRACTION: there is real work here (resolve the locale,
 * choose the pattern, keep the digits Western) and seven callers. Compare the
 * Numerals helper that ADR 0005 explicitly refused to write, which would have
 * returned its argument unchanged.
 *
 * WESTERN NUMERALS. Carbon's Arabic locale yields "14 نوفمبر 2026" — Arabic
 * month name, Western digits — which is exactly the decision in ADR 0005 and
 * needs no intervention. The test asserts no Arabic-Indic digit survives, so
 * if a future Carbon changes its mind this fails rather than drifting.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class Dates
{
    /** "14 November 2026" / "14 نوفمبر 2026" */
    public static function long(?Carbon $date): ?string
    {
        return $date?->locale(app()->getLocale())->isoFormat('D MMMM Y');
    }

    /** "14 November 2026, 15:04" — the comma follows the script. */
    public static function longWithTime(?Carbon $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $locale = app()->getLocale();

        /*
         * The Arabic comma (U+060C), not the Latin one. A Latin comma inside
         * an RTL run is laid out at the wrong end of the phrase by the
         * bidirectional algorithm, which is the same class of defect as the
         * one Part 0 fixed, in miniature.
         */
        $separator = $locale === 'ar' ? '، ' : ', ';

        return $date->locale($locale)->isoFormat('D MMMM Y').$separator.$date->format('H:i');
    }

    /** "15:04" — a 24-hour clock in both locales. */
    public static function time(?Carbon $date): ?string
    {
        return $date?->format('H:i');
    }
}
