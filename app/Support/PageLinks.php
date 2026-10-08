<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;

/**
 * The legal links the footer offers, and only the ones that actually resolve.
 *
 * WHY THIS IS CACHED
 *
 * The footer is on every page, so an uncached query here is one extra round
 * trip on every request on the site — to answer a question whose answer
 * changes perhaps twice a year. It is cached under one key and dropped
 * whenever a page is written, the same arrangement the settings map and the
 * redirect map use.
 *
 * It resolves to an empty list rather than throwing when nothing is published,
 * so a fresh install has a footer without legal links rather than a 500.
 */
final class PageLinks
{
    public const CACHE_KEY = 'pages.legal_links';

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function legal(): array
    {
        /*
         * THE CACHE KEY CARRIES THE LOCALE.
         *
         * Both halves of this map are locale-dependent: the label comes from
         * a translatable setting and the url carries the locale's prefix. One
         * shared key meant whichever locale warmed the cache served its
         * labels and its URLs to the other — an Arabic footer linking to the
         * English privacy page in English.
         *
         * A cache key built from request input is normally a way to let anyone
         * fill the cache with junk. Not here: the locale comes from a closed
         * set of two, so this is two entries, for ever.
         */
        return Cache::rememberForever(self::CACHE_KEY.'.'.Locale::current(), static function (): array {
            $slugs = [
                Page::PRIVACY => Settings::get('nav.privacy', 'Privacy'),
                Page::TERMS => Settings::get('nav.terms', 'Terms'),
            ];

            $published = Page::query()
                ->published()
                ->whereIn('slug', array_keys($slugs))
                ->pluck('title', 'slug');

            $links = [];

            foreach ($slugs as $slug => $label) {
                if (! $published->has($slug)) {
                    continue;
                }

                $links[] = [
                    // The nav label, not the page title: "Privacy" in a footer
                    // where the page itself is called "Privacy notice".
                    'label' => (string) $label,
                    'url' => route('pages.show', $slug),
                ];
            }

            return $links;
        });
    }

    /**
     * Forget every locale's copy, not merely the active one.
     *
     * A page published while an English admin session is open has to vanish
     * from the Arabic footer too, and the admin has no idea which locales
     * exist. Looping the closed set is the only version of this that cannot
     * leave a stale entry behind.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);   // the pre-locale key, for safety

        foreach (Locale::codes() as $locale) {
            Cache::forget(self::CACHE_KEY.'.'.$locale);
        }
    }
}
