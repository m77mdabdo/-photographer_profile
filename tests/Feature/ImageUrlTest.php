<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * The bug this exists for: every image on the site 404'd in a browser while
 * the whole suite passed.
 *
 * media_public built its URLs from env('APP_URL'), which was http://localhost
 * while the app was served on 127.0.0.1:8000, so every <img> pointed at a host
 * that was not serving the site. Nothing caught it because the suite renders
 * HTML and never fetches what the HTML points at — and because APP_URL in the
 * test environment happens to match the test client's host, so even a test
 * that HAD fetched the URL would have passed.
 *
 * The fix is structural: media URLs are root-relative, so they resolve against
 * whatever host served the page. These tests assert that property rather than
 * asserting a particular host, because a test that asserts a host is the same
 * mistake in a different file.
 */
class ImageUrlTest extends TestCase
{
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');

        /*
         * NOT faked with a url. The whole point is to exercise the real
         * media_public configuration, and Storage::fake() would replace the
         * configured url with one of its own — which is how a test can
         * "cover" this and still miss it.
         */
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();

        $this->world = new DemoWorld();
    }

    private function populate(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(4), ['landscape'], featured: true);
        $this->world->story('the-sitting', DemoWorld::simpleScore(3), ['portrait'], orientation: 'portrait');
    }

    /**
     * @return list<string>
     */
    private function imageUrls(string $html): array
    {
        $urls = [];

        foreach (preg_match_all('/<img[^>]*>/', $html, $tags) ? $tags[0] : [] as $tag) {
            if (preg_match('/\ssrc="([^"]+)"/', $tag, $m)) {
                $urls[] = $m[1];
            }
        }

        // Every candidate in every srcset, on <img> and on <source>.
        foreach (preg_match_all('/srcset="([^"]+)"/', $html, $sets) ? $sets[1] : [] as $srcset) {
            foreach (explode(',', $srcset) as $candidate) {
                $candidate = trim($candidate);

                if ($candidate !== '') {
                    $urls[] = preg_split('/\s+/', $candidate)[0];
                }
            }
        }

        return $urls;
    }

    // ----------------------------------------------------------------- 1

    public function test_every_image_url_in_every_page_is_root_relative(): void
    {
        $this->populate();

        foreach (['/', '/work', '/archive', '/work/salt-line'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $urls = $this->imageUrls($html);

            $this->assertNotEmpty($urls, "{$path} rendered no images to check");

            foreach ($urls as $url) {
                // Data URIs are inline LQIP, not requests; they are fine.
                if (str_starts_with($url, 'data:')) {
                    continue;
                }

                $this->assertStringStartsWith(
                    '/',
                    $url,
                    "{$path}: image URL is not root-relative, so it is pinned to one host: {$url}"
                );

                $this->assertStringStartsNotWith(
                    '//',
                    $url,
                    "{$path}: protocol-relative URL is still pinned to a host: {$url}"
                );
            }
        }
    }

    public function test_the_media_disks_are_not_configured_from_app_url(): void
    {
        /*
         * The property, at the source. Asserting only the rendered HTML would
         * pass again the moment somebody "fixes" a deployment by putting the
         * absolute URL back in config.
         */
        foreach (['public', 'media_public'] as $disk) {
            $url = config("filesystems.disks.{$disk}.url");

            $this->assertIsString($url, "{$disk} has no url configured");
            $this->assertStringStartsWith('/', $url, "{$disk} url must be root-relative, got: {$url}");
        }

        $source = (string) file_get_contents(config_path('filesystems.php'));

        // Comments explain why APP_URL is not used; the config must not use it.
        $declarations = (string) preg_replace('/\/\*.*?\*\//s', '', $source);
        $declarations = (string) preg_replace('/\/\/.*$/m', '', $declarations);

        $localDisks = substr($declarations, 0, strpos($declarations, "'s3'") ?: strlen($declarations));

        $this->assertStringNotContainsString(
            'APP_URL',
            $localDisks,
            'a local disk builds URLs from APP_URL again'
        );
    }

    // ----------------------------------------------------------------- 2

    public function test_og_image_and_twitter_image_are_absolute(): void
    {
        $this->populate();

        foreach (['/', '/work/salt-line'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match('/<meta property="og:image" content="([^"]+)">/', $html, $og);
            preg_match('/<meta name="twitter:image" content="([^"]+)">/', $html, $tw);

            $this->assertNotEmpty($og, "{$path} has no og:image");

            foreach ([$og[1], $tw[1] ?? $og[1]] as $url) {
                $this->assertMatchesRegularExpression(
                    '#^https?://#',
                    $url,
                    "{$path}: a scraper has nothing to resolve a relative URL against: {$url}"
                );
            }

            // And it points at a real rendition, not the original.
            $this->assertStringContainsString('/storage/media/', $og[1]);
        }
    }

    public function test_absolutising_leaves_a_cdn_url_alone(): void
    {
        /*
         * Moving media to S3 or a CDN makes the disk return absolute URLs.
         * Absolutising those again would produce
         * https://example.com/https://bucket.s3.amazonaws.com/…
         */
        $this->assertSame(
            'https://cdn.example.com/media/x.jpg',
            \App\Support\AbsoluteUrl::for('https://cdn.example.com/media/x.jpg')
        );

        $this->assertSame(
            '//cdn.example.com/media/x.jpg',
            \App\Support\AbsoluteUrl::for('//cdn.example.com/media/x.jpg')
        );

        $this->assertStringStartsWith('http', (string) \App\Support\AbsoluteUrl::for('/storage/media/x.jpg'));
        $this->assertNull(\App\Support\AbsoluteUrl::for(null));
    }

    /**
     * The check that would actually have caught the bug, and the reason it
     * cannot live in this file.
     */
    public function test_the_variant_path_resolves_to_a_file_on_the_public_disk(): void
    {
        $this->populate();

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('#src="(/storage/media/[^"]+)"#', $html, $found);

        $this->assertNotEmpty($found, 'no media URL in the rendered page');

        /*
         * A REQUEST is what was missing, and a request is what this cannot
         * make: Laravel's test client routes through the HTTP kernel, and
         * /storage/... is served by the web server from a symlink, not by a
         * route. So this asserts the next best thing — that the path the page
         * emits maps to a file that exists on the disk that serves it.
         *
         * The real fetch lives in the smoke script; see the report.
         */
        $path = str_replace('/storage/media/', '', $found[1]);

        $this->assertTrue(
            Storage::disk('media_public')->exists($path),
            "the page points at a file the disk does not have: {$found[1]}"
        );
    }
}
