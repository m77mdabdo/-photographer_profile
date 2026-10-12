<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The noun a paginator counts, in both locales and both grammatical numbers.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * Str::plural() WAS APPLYING ENGLISH GRAMMAR TO AN ARABIC PAGE.
 *
 * The paginator read `Str::plural($unit, $total)` where $unit was an English
 * noun passed in from the view — 'frame' or 'series'. Two things wrong with
 * that at once: the noun was not translatable, and the pluralisation rule is
 * English, so an Arabic page counted "7 frames".
 *
 * Arabic has a dual as well as a plural, and this deliberately does not use
 * it. "٣ إطارات" reads correctly for every count a paginated archive will
 * show, and an interface that changed noun form at exactly two would be more
 * surprising than consistent. One form for a count of one, one for the rest.
 *
 * The keys are written out for the same reason as SocialLinks: a key built by
 * interpolation cannot be grepped, so nothing could prove the settings rows
 * were read by anything.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class PagerUnits
{
    /** unit => [the one-form key, the many-form key] */
    private const UNITS = [
        'frame' => ['shared.unit_frame_one', 'shared.unit_frame'],
        'series' => ['shared.unit_series_one', 'shared.unit_series'],
    ];

    /** "7 frames", or "إطار واحد". */
    public static function label(?string $unit, int $count): string
    {
        [$one, $many] = self::UNITS[$unit] ?? self::UNITS['frame'];

        return SiteCopy::line($count === 1 ? $one : $many, ['count' => $count]);
    }

    /** @return list<string> the units a view may ask for */
    public static function names(): array
    {
        return array_keys(self::UNITS);
    }
}
