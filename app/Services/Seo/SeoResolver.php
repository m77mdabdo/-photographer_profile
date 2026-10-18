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
        /*
         * THE SITE-WIDE SHARE IMAGE IS THE OWNER'S CHOICE.
         *
         * config('seo.default_og_image') was hardcoded null, so every page
         * without an image of its own — the contact page, the about page, a
         * flat page, every error page — shared as a bare text card. The owner
         * had no way to set one.
         *
         * A page's own image still wins: a project shares its cover, not the
         * studio's default.
         */
        $image = $page->ogImage ?? \App\Support\SiteImages::openGraph();

        return new SeoData(
            title: $title,
            description: $description,
            canonical: $page->addressless ? null : ($page->canonical ?? $this->canonical()),

            // See robots() — outside production this is never index, follow.
            robots: $this->robots($page),

            // Open Graph mirrors the page values unless given something better.
            ogTitle: $page->ogTitle ?? $title,
            ogDescription: $page->ogDescription ?? $description,
            ogImage: $image,
            ogType: $page->ogType ?? 'website',

            // A card with an image should be large; without one, a summary.
            twitterCard: $page->twitterCard ?? ($image !== null ? 'summary_large_image' : 'summary'),

            rawTitle: true,

            /*
             * Carried forward, because the LAYOUT reads it too: the hreflang
             * set is emitted beside the canonical rather than inside the SEO
             * component, and an addressless page must not have alternates
             * either.
             */
            addressless: $page->addressless,
        );
    }

    /**
     * The robots directive, and the one thing a page may not override.
     *
     * OUTSIDE PRODUCTION, EVERY PAGE IS noindex, nofollow.
     *
     * A staging copy being indexed is one of the most damaging and most common
     * SEO failures there is: the staging site competes with the real one for
     * its own terms, and usually wins some of them with half-finished content
     * or a login prompt. robots.txt alone does not prevent it — a disallowed
     * page that something links to can still be listed as a bare URL — so the
     * meta tag is the belt to that braces.
     *
     * It is enforced HERE, above every page's own preference, because the one
     * way this protection fails is a single page having a good reason to opt
     * out. There is no good reason. A page that wants to be noindex in
     * production still gets noindex; a page that wants to be indexed gets it
     * only where indexing is correct.
     */
    private function robots(SeoData $page): string
    {
        if (! app()->environment('production')) {
            return 'noindex, nofollow';
        }

        return $page->robots ?? (string) config('seo.default_robots');
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
