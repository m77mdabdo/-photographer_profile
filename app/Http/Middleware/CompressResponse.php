<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 35 A6 — THE 190 KiB NOBODY WAS COMPRESSING.
 *
 * Lighthouse, against a production build with config, routes and views cached
 * and OPcache on, scored `uses-text-compression` at ZERO: "Est savings of
 * 190 KiB" on the home page alone. The home document is 71 KiB of HTML, the
 * stylesheet 76 KiB and the vendor script 78 KiB, and every byte of all three
 * went over the wire raw. On Lighthouse's emulated mobile link that is most of
 * a second before anything can paint.
 *
 * WHOSE JOB THIS NORMALLY IS, AND WHY IT IS ALSO OURS. Compression is
 * conventionally the web server's: mod_deflate, nginx gzip, a CDN. The
 * repository now ships those directives too — see public/.htaccess — but a
 * directive in a file only helps the deployments that read it, and this
 * application has no way to know what it will be put behind. A studio moving
 * the site to a cheap PHP host should not discover a 190 KiB regression by
 * being told about it.
 *
 * So the application compresses when nothing else has, and never when
 * something else already did:
 *
 *   · NOT IF THE RESPONSE IS ALREADY ENCODED. Content-Encoding present means
 *     a server module got there first; compressing again produces bytes no
 *     browser can read.
 *   · NOT IF THE CLIENT DID NOT ASK. Accept-Encoding is the only permission
 *     that matters, and a client that omits it gets plain bytes.
 *   · NOT FOR STREAMS OR FILES. A StreamedResponse is streamed precisely
 *     because its body does not fit in memory, and a BinaryFileResponse is
 *     usually an image or a video — already compressed, and often served by
 *     the file system rather than by PHP.
 *   · NOT FOR ALREADY-COMPRESSED TYPES. An avif, a woff2 or a jpeg gains
 *     nothing and costs CPU; the list below is text and text-like only.
 *   · NOT FOR SHORT BODIES. Below about a kilobyte the gzip header is most of
 *     the saving, and a 304 or a 204 has no body at all.
 *
 * Vary: Accept-Encoding is set whether or not this compresses, because the
 * cache in front of it has to know that the response varies — a shared cache
 * that stored the gzipped copy and served it to a client that did not ask
 * would be this middleware's one way of breaking a page.
 * ───────────────────────────────────────────────────────────────────────────
 */
class CompressResponse
{
    /** Below this, the header costs more than the saving. */
    private const MINIMUM_BYTES = 1024;

    /** Text and text-like media types. Everything else is left alone. */
    private const COMPRESSIBLE = [
        'text/',
        'application/json',
        'application/xml',
        'application/javascript',
        'application/rss+xml',
        'application/atom+xml',
        'image/svg+xml',
        '+json',
        '+xml',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * The Vary goes on every response this middleware sees, including the
         * ones it decides not to compress: a cache keyed without it would
         * serve one client's answer to another.
         */
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Encoding', ', '));

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $compressed = gzencode((string) $response->getContent(), 6);

        if ($compressed === false || $compressed === '') {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        if (! str_contains((string) $request->headers->get('Accept-Encoding'), 'gzip')) {
            return false;
        }

        if (! function_exists('gzencode')) {
            return false;
        }

        $content = (string) $response->getContent();

        if (strlen($content) < self::MINIMUM_BYTES) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        foreach (self::COMPRESSIBLE as $fragment) {
            if (str_contains($type, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
