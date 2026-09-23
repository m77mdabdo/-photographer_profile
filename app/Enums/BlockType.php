<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The vocabulary of a project's story.
 *
 * mediaSlots() is how many images the layout expects. The Story Builder uses
 * it to decide how many pickers to show and to warn when a block is
 * incomplete; it is not a database constraint, because a half-filled block is
 * a legitimate draft state.
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

    public function mediaSlots(): int
    {
        return match ($this) {
            self::FullWidthImage, self::ContainedImage, self::ImageText, self::TextImage => 1,
            self::PortraitPair, self::LandscapePair => 2,
            self::ThreeImageGrid => 3,

            // A strip holds an open-ended run of images; the builder treats
            // anything negative as "as many as you like".
            self::GalleryStrip => -1,

            self::FullWidthText, self::Quote, self::Video, self::Embed,
            self::Spacer, self::Credits => 0,
        };
    }
}
