<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The hero's four stage labels.
 *
 * Darkroom vocabulary mapped onto the sections the home page has: expose is
 * the work chosen, develop the one story worked up in full, fix the archive
 * where an image is made permanent, print the closing invitation.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * The keys are written out rather than built with "stage.{$index}.label".
 * That interpolation pre-dates this task and had the same consequence as the
 * two other cases of it: nothing could prove the four settings rows were read
 * by anything, so the test that asserts every editable setting IS read
 * reported all four as dead fields.
 *
 * A stage with no label is DROPPED rather than numbered blankly — a studio
 * that wants three stages sets the fourth to empty and gets three.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class HeroStages
{
    /** @var list<string> */
    private const KEYS = [
        'stage.1.label',
        'stage.2.label',
        'stage.3.label',
        'stage.4.label',
    ];

    /**
     * @return list<array{number: string, label: string}>
     */
    public static function all(): array
    {
        $stages = [];

        foreach (self::KEYS as $index => $key) {
            $label = Settings::get($key);

            if (! is_string($label) || trim($label) === '') {
                continue;
            }

            $stages[] = [
                // 01, 02 — a Western numeral in both locales. See ADR 0005.
                'number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'label' => trim($label),
            ];
        }

        return $stages;
    }
}
