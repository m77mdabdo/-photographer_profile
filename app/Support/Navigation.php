<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Support\Facades\Cache;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * THE PUBLIC NAVIGATION, RESOLVED AND CACHED.
 *
 * Read on every public page, in the layout, above the fold — so it is cached
 * whole rather than queried per request, exactly like PageLinks.
 *
 * PER LOCALE, because the labels are. One shared key meant whichever locale
 * warmed the cache served its words to both, which is the bug PageLinks
 * carries a paragraph about; this is the same mechanism and the same key
 * shape so the two cannot drift apart in their reasoning.
 *
 * The items are resolved to ADDRESSES here, which means an item pointing at a
 * removed route or an unpublished page is dropped before any template sees
 * it. A template cannot be expected to decide whether a link is real.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class Navigation
{
    public const CACHE_KEY = 'navigation';

    /**
     * One menu's visible items as [label, url, external] in order.
     *
     * @return list<array{label: string, url: string, external: bool}>
     */
    public static function for(string $location): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY.'.'.$location.'.'.Locale::current(),
            static function () use ($location): array {
                $menu = Menu::query()->where('location', $location)->first();

                if ($menu === null) {
                    return [];
                }

                return $menu->visibleItems()
                    ->map(fn (MenuItem $item): array => [
                        'label' => (string) $item->label,
                        'url' => (string) $item->url(),
                        'external' => $item->isExternal(),
                    ])
                    // A link with no words is not a link anybody can use.
                    ->filter(fn (array $entry): bool => $entry['label'] !== '')
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * Drop every locale of every menu.
     *
     * Called from the models' saved/deleted hooks. Looping the closed set of
     * locations and locales is the only version of this that cannot leave a
     * stale entry behind — the same argument as PageLinks::forget().
     */
    public static function forget(): void
    {
        foreach ([Menu::HEADER, Menu::FOOTER] as $location) {
            foreach (Locale::codes() as $locale) {
                Cache::forget(self::CACHE_KEY.'.'.$location.'.'.$locale);
            }
        }
    }
}
