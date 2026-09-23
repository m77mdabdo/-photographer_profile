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
        );
    }
}
