<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use \Tests\Support\AssertsNoJavaScript;
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();

        $this->world = new DemoWorld();
    }

    // ----------------------------------------------------------------- 13

    public function test_it_renders_everything_from_the_database(): void
    {
        Client::factory()->create(['name' => 'Meridian Press', 'is_active' => true]);

        $html = $this->get('/about')->assertOk()->getContent();

        foreach (['about.heading', 'about.approach_heading', 'about.clients_heading',
                  'about.cta', 'site.location', 'site.disciplines'] as $key) {
            $this->assertStringContainsString(
                e((string) Settings::get($key)),
                $html,
                "{$key} did not reach the page"
            );
        }

        // The prose arrives as paragraphs rather than one run-on block.
        $this->assertGreaterThanOrEqual(4, substr_count($html, '<p>'), 'the biography lost its paragraphs');

        $this->assertStringContainsString('Meridian Press', $html);
        $this->assertStringContainsString(route('contact.show'), $html);
        $this->assertNoExecutableJavaScript($html, '/about');
    }

    public function test_the_page_composes_without_a_portrait(): void
    {
        // No portrait chosen yet: a studio that has not picked one still has
        // an about page, and it must not render a broken frame.
        $this->assertNull(Settings::get('about.portrait_media_id'));

        $html = $this->get('/about')->assertOk()->getContent();

        $this->assertStringNotContainsString('studio__portrait', $html);
        $this->assertStringContainsString('studio__title', $html);
    }

    public function test_the_portrait_is_the_lcp_and_nothing_else_competes(): void
    {
        $portrait = $this->world->media('portrait');

        Settings::set('about.portrait_media_id', (string) $portrait->id, 'string', 'about');

        $html = $this->get('/about')->assertOk()->getContent();

        $this->assertStringContainsString('studio__portrait', $html);

        // Exactly one image, and it is the high-priority one.
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));
        $this->assertSame(1, substr_count($html, 'decoding="sync"'));

        // The LCP never carries a loading attribute at all.
        $img = substr($html, (int) strpos($html, '<img'));
        $img = substr($img, 0, (int) strpos($img, '>'));

        $this->assertStringNotContainsString('loading=', $img);

        // And it is the og:image, so a shared link shows the photographer.
        $this->assertMatchesRegularExpression('/<meta property="og:image" content="https?:\/\//', $html);
    }

    public function test_a_missing_or_unprocessed_portrait_is_ignored(): void
    {
        foreach (['999999', 'not-a-number', ''] as $value) {
            Settings::set('about.portrait_media_id', $value, 'string', 'about');

            $html = $this->get('/about')->assertOk()->getContent();

            $this->assertStringNotContainsString('studio__portrait', $html, "value '{$value}' rendered a frame");
        }
    }

    public function test_an_inactive_client_never_appears(): void
    {
        Client::factory()->create(['name' => 'Shown Client', 'is_active' => true]);
        Client::factory()->inactive()->create(['name' => 'Hidden Client']);

        $html = $this->get('/about')->assertOk()->getContent();

        $this->assertStringContainsString('Shown Client', $html);
        $this->assertStringNotContainsString('Hidden Client', $html);
    }
}
