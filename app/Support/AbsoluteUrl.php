<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Turns an asset URL into one that works outside the page it came from.
 *
 * WHY THIS EXISTS
 *
 * Media URLs on this site are ROOT-RELATIVE — "/storage/media/…" — because a
 * relative src resolves against whatever host and port served the page, so
 * local, staging and production all work with no configuration and no way to
 * get it wrong. That is the right default for every image in the document.
 *
 * It is exactly wrong for the handful of places where the URL leaves the
 * document: og:image, twitter:image, an image sitemap, an email. A scraper
 * reading "/storage/media/x.jpg" out of a meta tag has nothing to resolve it
 * against, so those must be absolute, and they must say so at the point of
 * use rather than by making every image on the site absolute for their sake.
 *
 * A disk that already returns absolute URLs — S3, a CDN — is passed through
 * untouched, so moving media to a bucket does not produce
 * "https://example.com/https://bucket.s3…".
 */
final class AbsoluteUrl
{
    public static function for(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Already absolute (http, https) or protocol-relative: leave it alone.
        if (Str::startsWith($url, ['http://', 'https://', '//'])) {
            return $url;
        }

        return URL::to($url);
    }
}
