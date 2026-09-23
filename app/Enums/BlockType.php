<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The vocabulary of a project's story.
 *
 * Media bounds are expressed as two explicit methods rather than a single
 * count with a magic value. minMedia()/maxMedia() answer different questions
 * — "is this block finished?" and "may another image be added?" — and a
 * sentinel like -1 silently answers both wrongly in a loop.
 */
enum BlockType: string
{
    case FullWidthImage = 'full_width_image';
    case ContainedImage = 'contained_image';
    case PortraitPair = 'portrait_pair';
    case LandscapePair = 'landscape_pair';
    case ThreeImageGrid = 'three_image_grid';
    case ImageText = 'image_text';
    case TextImage = 'text_image';
    case FullWidthText = 'full_width_text';
    case Quote = 'quote';
    case Video = 'video';
    case Embed = 'embed';
    case Spacer = 'spacer';
    case Credits = 'credits';
    case GalleryStrip = 'gallery_strip';

    public function label(): string
    {
        return match ($this) {
            self::FullWidthImage => 'Full-width image',
            self::ContainedImage => 'Contained image',
            self::PortraitPair => 'Portrait pair',
            self::LandscapePair => 'Landscape pair',
            self::ThreeImageGrid => 'Three-image grid',
            self::ImageText => 'Image with text',
            self::TextImage => 'Text with image',
            self::FullWidthText => 'Full-width text',
            self::Quote => 'Pull quote',
            self::Video => 'Video',
            self::Embed => 'Embed',
            self::Spacer => 'Spacer',
            self::Credits => 'Credits',
            self::GalleryStrip => 'Gallery strip',
        };
    }

    /**
     * How the type picker groups these for a human.
     */
    public function group(): string
    {
        return match ($this) {
            self::FullWidthImage, self::ContainedImage, self::PortraitPair,
            self::LandscapePair, self::ThreeImageGrid, self::GalleryStrip => 'Image',

            self::ImageText, self::TextImage, self::FullWidthText,
            self::Quote, self::Credits => 'Text',

            self::Video, self::Embed, self::Spacer => 'Media',
        };
    }

    /**
     * The fewest images this block needs before it is complete.
     */
    public function minMedia(): int
    {
        return match ($this) {
            self::FullWidthImage, self::ContainedImage, self::ImageText, self::TextImage => 1,
            self::PortraitPair, self::LandscapePair => 2,
            self::ThreeImageGrid => 3,
            self::GalleryStrip => 1,

            self::FullWidthText, self::Quote, self::Video,
            self::Embed, self::Spacer, self::Credits => 0,
        };
    }

    /**
     * The most images this block accepts. Null means unbounded — only the
     * gallery strip, which is an open-ended run of images by definition.
     */
    public function maxMedia(): ?int
    {
        return match ($this) {
            self::FullWidthImage, self::ContainedImage, self::ImageText, self::TextImage => 1,
            self::PortraitPair, self::LandscapePair => 2,
            self::ThreeImageGrid => 3,

            self::GalleryStrip => null,

            self::FullWidthText, self::Quote, self::Video,
            self::Embed, self::Spacer, self::Credits => 0,
        };
    }

    public function acceptsMedia(): bool
    {
        return $this->maxMedia() === null || $this->maxMedia() > 0;
    }

    public function isUnbounded(): bool
    {
        return $this->maxMedia() === null;
    }

    /**
     * Whether this type has any prose the editor writes — a caption, a body,
     * a quote. Derived from allowedDataKeys() it would be wrong: a Spacer's
     * only key is a numeric height, which is configuration, not text.
     */
    public function hasText(): bool
    {
        return match ($this) {
            self::Spacer => false,
            default => true,
        };
    }

    /**
     * Exactly which keys this type may store in `data`.
     *
     * Media ids are absent from every list on purpose: block images live in
     * media_usages, which is what keeps MediaPolicy's delete protection
     * working. A saving guard enforces this rather than trusting the list.
     *
     * @return list<string>
     */
    public function allowedDataKeys(): array
    {
        return match ($this) {
            self::FullWidthImage, self::ContainedImage => ['caption'],
            self::PortraitPair, self::LandscapePair, self::ThreeImageGrid => ['caption'],
            self::GalleryStrip => ['caption'],

            self::ImageText, self::TextImage => ['heading', 'body'],
            self::FullWidthText => ['heading', 'body'],
            self::Quote => ['quote', 'attribution'],
            self::Credits => ['heading'],

            self::Video => ['url', 'caption', 'poster_alt'],
            self::Embed => ['url', 'caption'],
            self::Spacer => ['height'],
        };
    }

    /**
     * Keys that must be present and non-empty for the block to be valid.
     *
     * @return list<string>
     */
    public function requiredDataKeys(): array
    {
        return match ($this) {
            self::Quote => ['quote'],
            self::FullWidthText => ['body'],
            self::ImageText, self::TextImage => ['body'],
            self::Video, self::Embed => ['url'],
            default => [],
        };
    }
}
