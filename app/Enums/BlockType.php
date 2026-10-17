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

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * TASK 35 H4 — THE FIFTH ENUM, WHICH TASK 34 PART F MISSED.
     *
     * Part F translated PublishStatus, InquiryStatus, MediaStatus and
     * UserRole, because PanelArabicRenderTest found them: all four are
     * rendered by Filament columns and filters on screens that scan covers.
     * This one is rendered by the STORY BUILDER, a Livewire component with
     * its own blade, and so "Full-width image" has been sitting in the
     * Arabic panel's type picker since the builder was written.
     *
     * Fourteen labels and three group names, in the language files where
     * every other string in this panel lives.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function label(): string
    {
        return (string) __('studio.block.'.$this->value);
    }

    /**
     * How the type picker groups these for a human.
     */
    public function group(): string
    {
        $group = match ($this) {
            self::FullWidthImage, self::ContainedImage, self::PortraitPair,
            self::LandscapePair, self::ThreeImageGrid, self::GalleryStrip => 'image',

            self::ImageText, self::TextImage, self::FullWidthText,
            self::Quote, self::Credits => 'text',

            self::Video, self::Embed, self::Spacer => 'media',
        };

        return (string) __('studio.block.group_'.$group);
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

            // A poster frame, optional (minMedia is 0). The facade renders it
            // when present and falls back to a plain label when it is not.
            self::Video => 1,

            self::FullWidthText, self::Quote,
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
        /*
         * 'descriptions' holds per-position alt text, keyed by the image's
         * index within the block. The key is deliberately not "alt_images" or
         * similar: the saving guard rejects anything matching /media|image|
         * photo/i, and rightly so. It stores text, never ids.
         */
        return match ($this) {
            self::FullWidthImage, self::ContainedImage => ['caption', 'descriptions'],
            self::PortraitPair, self::LandscapePair, self::ThreeImageGrid => ['caption', 'descriptions'],
            self::GalleryStrip => ['caption', 'descriptions'],

            self::ImageText, self::TextImage => ['heading', 'body', 'descriptions'],
            self::FullWidthText => ['heading', 'body'],
            self::Quote => ['quote', 'attribution'],
            self::Credits => ['heading'],

            self::Video => ['url', 'caption', 'poster_alt', 'descriptions'],
            self::Embed => ['url', 'caption'],
            self::Spacer => ['height'],
        };
    }

    /**
     * Keys inside data that hold READER-FACING TEXT and therefore translate.
     *
     * LOCALE KEYS NEST AT THE FIELD, NOT AT THE BLOCK.
     *
     * The alternative was `{"en": {...whole block...}, "ar": {...}}`, and it
     * is wrong for two reasons. It duplicates keys that have no language —
     * a spacer's `height`, a video's `url` — so a block could end up 120px
     * tall in English and 80px in Arabic, which is not a translation, it is a
     * bug with somewhere to live. And it makes the block's structure itself
     * per-locale, so `allowedDataKeys()` and `requiredDataKeys()` would have
     * to validate each locale separately and could disagree about whether a
     * block is valid.
     *
     * Nesting at the field keeps the block's shape single and canonical:
     *
     *     {"heading": {"en": "Forty minutes", "ar": "أربعون دقيقة"},
     *      "body":    {"en": "The chair was placed once…"},
     *      "height":  120}
     *
     * which is the SAME shape a translatable column has. One cast, one
     * fallback rule, one mental model — rather than a second arrangement that
     * exists only inside this one column.
     *
     * `descriptions` is per-image alt text keyed by image index, so its LEAVES
     * translate rather than the key itself: {"0": {"en": "…", "ar": "…"}}.
     *
     * @return list<string>
     */
    public function translatableDataKeys(): array
    {
        return array_values(array_filter(
            $this->allowedDataKeys(),
            // url is an address and height is a number. Neither has a language.
            static fn (string $key): bool => ! in_array($key, ['url', 'height'], true),
        ));
    }

    /** Keys whose values are a map of index => text, not text. */
    public function nestedTextKeys(): array
    {
        return array_values(array_intersect($this->allowedDataKeys(), ['descriptions']));
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
