<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Support\Locale;
use Database\Seeders\Concerns\ProtectsEditedContent;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * THE NAVIGATION THE SITE ALREADY HAD, AS ROWS.
 *
 * Task 34 D1 moved the public navigation out of layouts/public.blade.php,
 * where it was six hardcoded entries, into menu_items. This seeder puts those
 * same six in, so the site looks identical the moment the change lands and
 * the owner starts from what they already have rather than from an empty
 * menu.
 *
 * createIfMissing throughout: the one thing this must never do is reinstate a
 * link the studio removed, or rename one they renamed. It runs once and then
 * has no further opinion.
 *
 * THE LABELS COME FROM WHERE THEY ALREADY WERE. Settings holds nav.work,
 * nav.services and the rest in both locales — the owner has been editing them
 * since Task 12 — so the items are seeded with those values rather than with
 * fresh English. Nobody should have to retype their own navigation because
 * the storage behind it changed.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class NavigationSeeder extends Seeder
{
    use ProtectsEditedContent;

    public function run(): void
    {
        $header = $this->createIfMissing(Menu::class, ['location' => Menu::HEADER], []);
        $this->createIfMissing(Menu::class, ['location' => Menu::FOOTER], []);

        /*
         * The six, in the order the layout rendered them. Contact is last in
         * the header because it is the one action rather than one of the
         * sections — the same order a visitor met before this change.
         */
        $items = [
            'work.index' => 'nav.work',
            'services.index' => 'nav.services',
            'archive.index' => 'nav.archive',
            'journal.index' => 'nav.journal',
            'about' => 'nav.about',
            'contact.show' => 'nav.contact',
        ];

        $order = 0;

        foreach ($items as $route => $settingKey) {
            if (! app('router')->has($route)) {
                continue;
            }

            $this->createIfMissing(
                MenuItem::class,
                ['menu_id' => $header->getKey(), 'type' => MenuItem::TYPE_ROUTE, 'target' => $route],
                [
                    'label' => $this->labelFor($settingKey, $route),
                    'sort_order' => $order,
                    'is_active' => true,
                ],
            );

            $order += 10;
        }
    }

    /**
     * The label in every locale, from settings, falling back to the route's
     * own name so a missing setting produces something rather than a blank
     * link.
     *
     * @return array<string, string>
     */
    private function labelFor(string $key, string $route): array
    {
        /*
         * Read off the Setting MODEL rather than through Settings::get(),
         * whose second argument is a default and not a locale — and which
         * resolves to one locale by design. Seeding needs every locale at
         * once.
         */
        $setting = \App\Models\Setting::query()->where('key', $key)->first();

        $label = [];

        foreach (Locale::codes() as $locale) {
            $value = $setting?->getTranslation('value', $locale, fallback: false);

            $label[$locale] = is_string($value) && $value !== '' ? $value : $route;
        }

        return $label;
    }
}
