<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;

/**
 * The technical line under a photograph.
 *
 * Renders whatever the frame actually carries and nothing else. There are no
 * placeholders, no em-dashes standing in for a missing aperture and no "ISO —":
 * a line that invents the half it does not know is worse than no line, because
 * a reader cannot tell which half was real.
 *
 * If nothing is known, this is null, and the caller renders nothing.
 */
final readonly class ExposureLine
{
    private function __construct(
        /** @var list<string> */
        public array $parts,
    ) {}

    public static function for(?Media $media): ?self
    {
        if ($media === null) {
            return null;
        }

        $parts = [];

        if ($media->exif_focal_length !== null) {
            $parts[] = $media->exif_focal_length.'mm';
        }

        if ($media->exif_aperture !== null) {
            // 2.80 is 2.8; 11.00 is 11.
            $parts[] = 'f/'.rtrim(rtrim(number_format((float) $media->exif_aperture, 2, '.', ''), '0'), '.');
        }

        if ($media->exif_shutter !== null) {
            $parts[] = $media->exif_shutter;
        }

        if ($media->exif_iso !== null) {
            $parts[] = 'ISO '.$media->exif_iso;
        }

        return $parts === [] ? null : new self($parts);
    }

    /**
     * The compact form, for the hero and captions.
     */
    public function compact(): string
    {
        return implode('  ·  ', $this->parts);
    }

    /**
     * Body and lens, when they are known. Separate from the exposure itself
     * because a hero has room for four numbers and not for a lens name.
     */
    public static function body(?Media $media): ?string
    {
        if ($media === null) {
            return null;
        }

        $parts = array_values(array_filter([
            $media->exif_camera,
            $media->exif_lens,
        ]));

        return $parts === [] ? null : implode('  ·  ', $parts);
    }

    public function __toString(): string
    {
        return $this->compact();
    }
}
