<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Support\SeoData;

/**
 * Turns a page's partial SEO intent into a complete, renderable set of values.
 *
 * Precedence, highest first:
 *   1. explicit values on the given SeoData
 *   2. values derived from those (og:* falling back to the page title/description)
 *   3. global defaults from config/seo.php
 *
 * Everything it returns is final: the view renders what it is given and makes
 * no decisions of its own.
 */
class SeoResolver
{
    public function resolve(?SeoData $page = null): SeoData
    {
        $page ??= SeoData::make();

        $siteName = (string) config('seo.site_name');

        $title = $this->resolveTitle($page, $siteName);
        $description = $page->description ?? (string) config('seo.default_description');
        $image = $page->ogImage ?? config('seo.default_og_image');

        return new SeoData(
            title: $title,
            description: $description,
            canonical: $page->canonical ?? $this->canonical(),
            robots: $page->robots ?? (string) config('seo.default_robots'),

            // Open Graph mirrors the page values unless given something better.
            ogTitle: $page->ogTitle ?? $title,
            ogDescription: $page->ogDescription ?? $description,
            ogImage: $image,
            ogType: $page->ogType ?? 'website',

            // A card with an image should be large; without one, a summary.
            twitterCard: $page->twitterCard ?? ($image !== null ? 'summary_large_image' : 'summary'),

            rawTitle: true,
        );
    }

    /**
     * "{page title} — {site name}", unless the page asked for its title to be
     * used verbatim, or gave no title at all.
     */
    private function resolveTitle(SeoData $page, string $siteName): string
    {
        if ($page->title === null || $page->title === '') {
            return (string) config('seo.default_title');
        }

        if ($page->rawTitle) {
            return $page->title;
        }

        return $page->title.' — '.$siteName;
    }

    /**
     * The absolute URL of the current request with any query string removed, so
     * that ?page=2 and ?utm_source=... never fragment the canonical.
     */
    private function canonical(): string
    {
        return url()->current();
    }
}
