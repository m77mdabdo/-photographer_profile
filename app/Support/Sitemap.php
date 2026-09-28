<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Seo\SitemapBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * The sitemap cache, and the one place that knows how to drop it.
 *
 * Every segment plus the index live under keys derived from one prefix, so
 * invalidation is a fixed, enumerable list rather than a wildcard delete —
 * which matters because most cache stores cannot do a wildcard delete at all,
 * and the ones that can do it slowly.
 */
final class Sitemap
{
    public const PREFIX = 'sitemap.';

    public static function remember(string $segment, callable $build): string
    {
        return Cache::rememberForever(self::PREFIX.$segment, $build);
    }

    /**
     * Drop every sitemap document.
     *
     * Called from model events on everything publishable. It is deliberately
     * all-or-nothing: working out that publishing an article only invalidates
     * the journal segment, the index and possibly the image segment is exactly
     * the kind of cleverness that eventually serves a stale file, and the
     * saving is one cheap rebuild on the next crawl.
     */
    public static function forget(): void
    {
        foreach (array_merge(SitemapBuilder::SEGMENTS, ['index']) as $segment) {
            Cache::forget(self::PREFIX.$segment);
        }
    }
}
