<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads a date out of whatever somebody typed, and never complains.
 *
 * "Date, if you have one" is an invitation, not a field specification. People
 * answer it with "14 May", "next spring", "the week of the 3rd", "flexible",
 * and occasionally with an actual date. A validator that refuses any of those
 * is refusing an enquiry over the least important thing on the form.
 *
 * So this returns a date when it can be confident of one and null otherwise.
 * The caller stores the raw string either way, so nothing a sender wrote is
 * ever lost or rounded into something they did not say.
 */
final class LooseDate
{
    /**
     * Strings that parse to a date but do not mean one.
     *
     * strtotime is enthusiastic: it reads "may" as next May, "sat" as
     * Saturday, and — worst of all — "flexible" as nothing while "march"
     * becomes a real date the sender never gave. A bare month or weekday is
     * not a commitment, so these are treated as free text and kept as typed.
     */
    private const AMBIGUOUS = [
        'january', 'february', 'march', 'april', 'may', 'june', 'july',
        'august', 'september', 'october', 'november', 'december',
        'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept',
        'oct', 'nov', 'dec',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'mon', 'tue', 'tues', 'wed', 'thu', 'thur', 'thurs', 'fri', 'sat', 'sun',
        'now', 'today', 'tomorrow', 'yesterday',
        'spring', 'summer', 'autumn', 'winter', 'fall',
    ];

    /**
     * The parsed date, or null when the text does not clearly name one.
     */
    public static function parse(?string $text): ?CarbonImmutable
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (in_array(mb_strtolower($text), self::AMBIGUOUS, true)) {
            return null;
        }

        /*
         * A date needs a number in it somewhere. "Late spring" and "when the
         * light comes back" do not, and neither should become a timestamp.
         */
        if (! preg_match('/\d/', $text)) {
            return null;
        }

        /*
         * A slash date is decided by slashed() ALONE — including when it
         * decides it cannot tell. Falling through to the general parser undid
         * the whole point: 05/06/2027 came back as 6 May because PHP reads
         * slashes American-first, which is precisely the guess this refuses
         * to make.
         */
        $date = self::isSlashed($text) ? self::slashed($text) : self::loose($text);

        if ($date === null) {
            return null;
        }

        /*
         * A sanity window rather than a validation rule. Anything outside it
         * is a misparse — "07" becoming the year 2007, say — and the raw text
         * is the better record of what was meant.
         */
        if ($date->year < 1970 || $date->year > (int) date('Y') + 25) {
            return null;
        }

        return $date->startOfDay();
    }

    /**
     * Slash-separated dates, which PHP reads American-first.
     *
     * 14/05/2027 is unambiguous — there is no fourteenth month — so it is
     * read as day-first and kept. 05/06/2027 is genuinely ambiguous, and
     * guessing would put an enquiry in the wrong month; that one stays as
     * text, where it says exactly what the sender wrote.
     */
    private const SLASHED = '#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#';

    private static function isSlashed(string $text): bool
    {
        return preg_match(self::SLASHED, $text) === 1;
    }

    private static function slashed(string $text): ?CarbonImmutable
    {
        if (! preg_match(self::SLASHED, $text, $m)) {
            return null;
        }

        [, $first, $second, $year] = $m;

        if ((int) $first <= 12) {
            return null;   // could be either way round; do not guess
        }

        return self::loose(sprintf('%04d-%02d-%02d', self::year((int) $year), (int) $second, (int) $first));
    }

    private static function year(int $year): int
    {
        return $year >= 100 ? $year : 2000 + $year;
    }

    private static function loose(string $text): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($text);
        } catch (Throwable) {
            return null;
        }
    }
}
