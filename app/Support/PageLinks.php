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
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
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

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
