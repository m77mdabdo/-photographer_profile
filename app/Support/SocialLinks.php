<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The studio's social links, as the owner has set them.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THREE SLOTS, NOT A REPEATER, AND THE REASON IS THE SETTINGS TABLE.
 *
 * Settings are key-value rows read as one cached map; there is no list shape
 * in that design and inventing one — a JSON column, a `social` table — would
 * be a second storage mechanism for six strings. Three numbered pairs is the
 * shape the table already has, and three is the number a photography studio
 * actually uses.
 *
 * It is also honest about the limit: a fourth network means a migration, and a
 * settings page that promised unlimited rows would have to grow a repeater,
 * reordering and validation for a case nobody has asked for. If a fourth is
 * ever wanted, the answer is a fourth pair of keys.
 *
 * A LINK NEEDS BOTH HALVES. A label with no address is a link that goes
 * nowhere; an address with no label is a link nobody can read. Either alone
 * yields nothing, which is why the footer can render no list at all.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class SocialLinks
{
    /**
     * The slots, written out rather than built from a counter.
     *
     * "footer.social_{$slot}_label" is shorter and cannot be grepped — so
     * nothing can prove the settings row is read by anything, and the test
     * that asserts every editable setting IS read reported all six of these as
     * dead fields. A field the owner edits and waits on is a worse defect than
     * a missing one.
     *
     * @var list<array{string, string}>
     */
    private const SLOTS = [
        ['footer.social_1_label', 'footer.social_1_url'],
        ['footer.social_2_label', 'footer.social_2_url'],
        ['footer.social_3_label', 'footer.social_3_url'],
    ];

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function all(): array
    {
        $links = [];

        foreach (self::SLOTS as [$labelKey, $urlKey]) {
            $label = Settings::get($labelKey);
            $url = Settings::get($urlKey);

            if (! is_string($label) || trim($label) === '') {
                continue;
            }

            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $links[] = ['label' => trim($label), 'url' => trim($url)];
        }

        return $links;
    }

    /** How many numbered slots the settings table holds. */
    public static function slotCount(): int
    {
        return count(self::SLOTS);
    }
}
