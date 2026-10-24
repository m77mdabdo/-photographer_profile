<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MediaStatus;
use App\Models\Media;

/**
 * The two images that belong to the whole site rather than to one record.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * CHOSEN FROM THE LIBRARY BY ID, NEVER A PATH TYPED INTO A FIELD.
 *
 * A path is a string that can be wrong in a way nothing notices until a share
 * card renders a broken image in somebody else's timeline. An id is a
 * foreign key: the picture either exists and has renditions, or this returns
 * null and the markup is omitted.
 *
 * THE FAVICON DID NOT EXIST AT ALL. public/favicon.ico is Laravel's 0-byte
 * placeholder and no <link rel="icon"> appeared in any layout, so every
 * browser showed a generic page icon for a photographer's portfolio. Found by
 * the Part 2a inventory.
 *
 * Both read `status = ready`: an image still in the queue has no renditions,
 * and a <link rel="icon"> pointing at an unprocessed original would serve a
 * 40-megapixel JPEG as a 16-pixel icon.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class SiteImages
{
    private const OG_KEY = 'seo.og_image_media_id';

    private const FAVICON_KEY = 'seo.favicon_media_id';

    /** The default share image, used when a page has nothing better. */
    public static function openGraph(): ?Media
    {
        return self::resolve(self::OG_KEY);
    }

    /** The browser-tab icon. */
    public static function favicon(): ?Media
    {
        return self::resolve(self::FAVICON_KEY);
    }

    /**
     * The favicon as a URL and a mime type, or null.
     *
     * A small square rendition rather than the original: the 480 variant is
     * the smallest this pipeline builds, which is still larger than any icon
     * needs and very much smaller than a camera original.
     *
     * @return array{url: string, type: string}|null
     */
    public static function faviconLink(): ?array
    {
        $media = self::favicon();

        if ($media === null) {
            return null;
        }

        $url = $media->thumbnailUrl(480);

        if ($url === null) {
            return null;
        }

        /*
         * The rendition's own type, read from the variant rather than assumed.
         * A browser sent image/png for a WebP body renders nothing.
         */
        $variant = $media->variants
            ->sortBy('width')
            ->first(static fn ($candidate): bool => str_contains((string) $url, (string) $candidate->path));

        return [
            'url' => $url,
            'type' => $variant !== null ? 'image/'.$variant->format : 'image/jpeg',
        ];
    }

    private static function resolve(string $key): ?Media
    {
        $id = Settings::get($key);

        if (! is_string($id) || trim($id) === '' || ! ctype_digit(trim($id))) {
            return null;
        }

        return Media::query()
            ->where('status', MediaStatus::Ready)
            ->with('variants')
            ->find((int) trim($id));
    }
}
