<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;

/**
 * A page's SEO intent, independent of any request.
 *
 * Every property is nullable and means "not specified"; the resolver decides
 * what an unspecified value becomes. Nothing here reads the request, the
 * container or the config, so it can be constructed in a controller, a test
 * or a queued job identically.
 */
final readonly class SeoData
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $robots = null,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?Media $ogImage = null,
        public ?string $ogType = null,
        public ?string $twitterCard = null,
        /**
         * When true the title is used verbatim and the site name is not
         * appended. This is the "override the whole title" escape hatch.
         */
        public bool $rawTitle = false,
        /**
         * ───────────────────────────────────────────────────────────────────
         * TASK 36 C3 — A PAGE WITH NO ADDRESS WORTH NAMING.
         *
         * canonical: null means "the resolver decides", and the resolver
         * decides url()->current(). On an error page that is the address that
         * was ASKED FOR, which is a different thing: the 404 at
         * /no-such-page-REFLECTME emitted
         *
         *   <link rel="canonical" href="…/no-such-page-REFLECTME">
         *   <meta property="og:url" content="…/no-such-page-REFLECTME">
         *   three hreflang alternates carrying the same path
         *
         * — seven copies of a string the caller chose, in a response that
         * also carries the CSRF token and is gzipped. See App\Support\ReturnPath
         * for why that combination matters.
         *
         * It is also simply wrong. A canonical names the one true address for
         * a page's content; a 404 has no content and is already noindex. An
         * hreflang set has to be reciprocal, and /ar/no-such-page-REFLECTME
         * has nothing to reciprocate with.
         *
         * So this is a third state, distinct from "the resolver decides":
         * emit no canonical, no og:url and no alternates at all.
         * ───────────────────────────────────────────────────────────────────
         */
        public bool $addressless = false,
    ) {}

    public static function make(
        ?string $title = null,
        ?string $description = null,
        ?string $canonical = null,
        ?string $robots = null,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?Media $ogImage = null,
        // Null means "not specified": the resolver picks the type, and picks
        // the card size from whether an image is present. Defaulting them here
        // would make that decision unreachable.
        ?string $ogType = null,
        ?string $twitterCard = null,
        bool $rawTitle = false,
        bool $addressless = false,
    ): self {
        return new self(
            title: $title,
            description: $description,
            canonical: $canonical,
            robots: $robots,
            ogTitle: $ogTitle,
            ogDescription: $ogDescription,
            ogImage: $ogImage,
            ogType: $ogType,
            twitterCard: $twitterCard,
            rawTitle: $rawTitle,
            addressless: $addressless,
        );
    }
}
