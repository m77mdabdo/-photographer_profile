<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 35 A6 — THE 190 KiB.
 *
 * Measured by Lighthouse against a production build: `uses-text-compression`
 * scored zero, "Est savings of 190 KiB" on the home page, because nothing in
 * this stack compressed anything. The home document alone went over the wire
 * at 71,521 bytes and gzips to 10,813 — an 85% saving on the one response a
 * visitor waits for before anything can paint.
 *
 * The middleware is the floor, for the deployment that has no mod_deflate.
 * What is asserted here is the shape of it, because every rule in that class
 * is a way of breaking a page if it is wrong: compressing a stream, double-
 * encoding what a server already encoded, or sending gzip to a client that
 * never asked for it.
 * ───────────────────────────────────────────────────────────────────────────
 */
class CompressionTest extends TestCase
{
    use RefreshDatabase;

    private \Tests\Support\DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A PAGE WITH SOMETHING ON IT.
         *
         * The home page of an empty database is 6,984 bytes, and this first
         * asserted against 10,000 because that is what the development
         * database produces. A compression test measured on a page with no
         * content in it is measuring the layout.
         */
        $this->world = new \Tests\Support\DemoWorld;

        for ($i = 0; $i < 4; $i++) {
            $this->world->story('series-'.$i, \Tests\Support\DemoWorld::simpleScore(2), ['landscape']);
        }
    }

    public function test_a_page_is_compressed_for_a_client_that_asks(): void
    {
        $plain = $this->get('/');
        $plain->assertOk();

        $raw = strlen((string) $plain->getContent());

        $this->assertGreaterThan(10_000, $raw, 'the home page is too small for this test to mean anything');

        $gzipped = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get('/');

        $gzipped->assertOk();
        $gzipped->assertHeader('Content-Encoding', 'gzip');

        $body = (string) $gzipped->getContent();

        $this->assertLessThan($raw / 2, strlen($body), 'compression saved less than half');

        /*
         * THE SAME PAGE, NOT THE SAME BYTES — the CSP nonce Task 36 C added
         * is fresh per request, so this compares the two responses with the
         * nonce normalised and asserts separately that both carry one. A
         * body that decompressed to something else entirely still fails;
         * a body that lost its nonce fails on the count.
         */
        $decoded = (string) gzdecode($body);

        $this->assertGreaterThan(0, $this->nonceCount($decoded), 'the decompressed page carries no CSP nonce');
        $this->assertSame(
            $this->withoutNonces((string) $plain->getContent()),
            $this->withoutNonces($decoded),
            'the compressed body is not the page'
        );
    }

    /** A client that does not ask gets plain bytes. */
    public function test_a_client_that_does_not_ask_is_not_compressed(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => 'identity'])->get('/');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Encoding');
    }

    /**
     * Vary goes on the response either way: a shared cache that stored the
     * gzipped copy and served it to a client that did not ask for it would be
     * this middleware's one way of breaking a page it never saw.
     */
    public function test_every_response_varies_on_accept_encoding(): void
    {
        foreach (['/', '/work', '/no-such-page'] as $path) {
            $response = $this->get($path);

            $this->assertStringContainsString(
                'Accept-Encoding',
                (string) $response->headers->get('Vary'),
                "{$path} does not vary on Accept-Encoding",
            );
        }
    }

    /** Including the ones that never reach a route. */
    public function test_an_error_page_is_compressed_too(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get('/no-such-page-at-all');

        $response->assertNotFound();
        $response->assertHeader('Content-Encoding', 'gzip');
    }

    /**
     * A binary response is left alone. An avif is already compressed, and
     * gzipping it costs CPU to make it very slightly larger.
     */
    public function test_a_binary_response_is_left_alone(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get('/sitemap-images.xml');

        // XML is text and IS compressed — the stories above put enough in it.
        $response->assertOk();
        $response->assertHeader('Content-Encoding', 'gzip');

        // ...while a streamed or file response is not. Asserted on the class's
        // own rule rather than by finding a route that returns one, because
        // which routes stream is not this guarantee's business.
        $middleware = new \App\Http\Middleware\CompressResponse;

        $streamed = $middleware->handle(
            \Illuminate\Http\Request::create('/', 'GET', server: ['HTTP_ACCEPT_ENCODING' => 'gzip']),
            fn (): \Symfony\Component\HttpFoundation\StreamedResponse => new \Symfony\Component\HttpFoundation\StreamedResponse(
                fn () => print str_repeat('a', 5000),
                200,
                ['Content-Type' => 'text/plain'],
            ),
        );

        $this->assertFalse($streamed->headers->has('Content-Encoding'));
    }

    /** What a server already encoded is never encoded again. */
    public function test_an_already_encoded_response_is_not_touched(): void
    {
        $middleware = new \App\Http\Middleware\CompressResponse;
        $payload = (string) gzencode(str_repeat('already compressed. ', 500));

        $response = $middleware->handle(
            \Illuminate\Http\Request::create('/', 'GET', server: ['HTTP_ACCEPT_ENCODING' => 'gzip']),
            fn (): \Illuminate\Http\Response => new \Illuminate\Http\Response($payload, 200, [
                'Content-Type' => 'text/html',
                'Content-Encoding' => 'gzip',
            ]),
        );

        $this->assertSame($payload, $response->getContent(), 'the body was encoded twice');
    }

    /**
     * And the server-level directives ship too, because a static file never
     * reaches PHP: a stylesheet is read off disk and handed out by the web
     * server, which is where the other 154 KiB of that 190 lives.
     */
    public function test_the_server_directives_ship_with_the_repository(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('mod_deflate.c', $htaccess);
        $this->assertMatchesRegularExpression(
            '/AddOutputFilterByType DEFLATE [^\n]*text\/css/',
            $htaccess,
            'stylesheets are not in the compression list',
        );

        // Guarded, so a host without the module serves what it served before.
        $this->assertMatchesRegularExpression('/<IfModule mod_deflate\.c>/', $htaccess);

        // And the hashed assets are cached for a year, the unhashed ones are not.
        $this->assertStringContainsString('max-age=31536000, immutable', $htaccess);
        /*
         * The faces are NOT content-hashed, so they must not be immutable for
         * a year: a replaced face would never reach a returning visitor.
         */
        preg_match('/woff2\$">\s*\n\s*Header set Cache-Control "([^"]+)"/', $htaccess, $fontRule);

        $this->assertNotEmpty($fontRule, 'no cache rule for the webfonts');
        $this->assertStringNotContainsString('immutable', $fontRule[1]);
        $this->assertStringNotContainsString('31536000', $fontRule[1]);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 38 — THE MEASURING PROXY AGREES WITH WHAT WE ACTUALLY DEPLOY.
     *
     * bin/gzip-proxy.mjs stands in for mod_deflate when Lighthouse measures
     * LCP, because `php artisan serve` hands static files out raw and would
     * report a compression defect the real deployment does not have. Task 35
     * set this project's LCP budget through exactly such a proxy and did not
     * commit it, so the measurement behind the budget was unreproducible for
     * three tasks.
     *
     * A proxy that compresses MORE than the deployment makes the site look
     * faster than it is, which is the direction a budget must never drift in.
     * So the two lists are compared here rather than kept in step by memory.
     *
     * `text/` is allowed to be a prefix superset of the .htaccess's four
     * text types: matching more of text/* cannot under-compress relative to
     * the list. Everything else must be named.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_the_measuring_proxy_compresses_what_the_server_is_told_to(): void
    {
        $proxy = (string) file_get_contents(base_path('bin/gzip-proxy.mjs'));
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        preg_match('/const COMPRESSIBLE = \/(.+?)\/\n/', $proxy, $found);
        $this->assertNotEmpty($found, 'bin/gzip-proxy.mjs no longer declares COMPRESSIBLE.');

        /*
         * `#` as the delimiter, not `/`. The JavaScript literal escapes its
         * slashes as \/ ; unescaping them and then wrapping the result in /…/
         * ends the pattern at the first media type and PHP warns "Unknown
         * modifier '|'" — which the first version of this test did, reporting
         * every type as a MISS and blaming the proxy.
         */
        $pattern = '#'.str_replace('\\/', '/', $found[1]).'#';

        preg_match_all('/AddOutputFilterByType DEFLATE (.+)/', $htaccess, $lines);
        $this->assertNotEmpty($lines[1], 'public/.htaccess no longer lists any DEFLATE types.');

        $types = [];

        foreach ($lines[1] as $line) {
            foreach (preg_split('/\s+/', trim($line)) ?: [] as $type) {
                if ($type !== '') {
                    $types[] = $type;
                }
            }
        }

        $this->assertGreaterThan(5, count($types), 'Too few types parsed to be asserting anything.');

        foreach ($types as $type) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $type,
                "public/.htaccess compresses {$type} but the measuring proxy does not, "
                .'so a measurement through it understates what the deployment sends.'
            );
        }

        /* And it must not compress what the server is told to leave alone. */
        foreach (['image/jpeg', 'image/avif', 'image/webp', 'font/woff2', 'application/zip'] as $binary) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $binary,
                "The measuring proxy compresses {$binary}, which the deployment does not."
            );
        }
    }

    /**
     * And it uses the same floor, so it cannot report a saving on a body the
     * application would have sent raw.
     */
    public function test_the_measuring_proxy_uses_the_middleware_floor(): void
    {
        $proxy = (string) file_get_contents(base_path('bin/gzip-proxy.mjs'));

        preg_match('/const FLOOR = (\d+)/', $proxy, $found);
        $this->assertNotEmpty($found, 'bin/gzip-proxy.mjs no longer declares FLOOR.');

        $middleware = new \ReflectionClass(\App\Http\Middleware\CompressResponse::class);

        $this->assertSame(
            $middleware->getConstant('MINIMUM_BYTES'),
            (int) $found[1],
            'The measuring proxy and CompressResponse disagree about the smallest body worth compressing.'
        );
    }
}
