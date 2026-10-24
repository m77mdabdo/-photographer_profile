<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use App\Support\Settings;
use App\Support\Sitemap;
use Database\Seeders\LegalPagesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\AssertsNoJavaScript;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class RobotsAndStructuredDataTest extends TestCase
{
    use AssertsNoJavaScript;
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        Settings::flush();
        $this->seed(SettingsSeeder::class);
        Settings::flush();
        Sitemap::forget();

        $this->world = new DemoWorld;
    }

    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    // ----------------------------------------------------------------- 7

    public function test_robots_txt_is_correct_in_production(): void
    {
        $this->asProduction();

        $body = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        // It allows the site.
        $this->assertStringNotContainsString("\nDisallow: /\n", $body);

        // It disallows everything that is not worth crawl budget.
        foreach (['/admin', '/login', '/livewire', '/_dev', '/contact/sent', '/archive?'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $body, "{$path} is not disallowed");
        }

        // It names the sitemap index, absolutely.
        $this->assertStringContainsString('Sitemap: '.route('sitemap.index'), $body);
        $this->assertMatchesRegularExpression('#Sitemap: https?://#', $body);

        // And on a photography site, images are explicitly fetchable.
        $this->assertStringContainsString('Allow: /storage/media/', $body);
    }

    /**
     * THE STAGING LEAK.
     *
     * A staging copy deployed with production's robots.txt gets crawled and
     * then competes with the real site for its own terms. This is the single
     * most common and most damaging SEO failure there is.
     */
    public function test_robots_txt_disallows_everything_outside_production(): void
    {
        foreach (['local', 'staging', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            $body = $this->get('/robots.txt')->assertOk()->getContent();

            $this->assertStringContainsString("User-agent: *\nDisallow: /", $body, "{$environment} is crawlable");

            // And it never names a sitemap, which would invite exactly the
            // crawl the Disallow is refusing.
            $this->assertStringNotContainsString('Sitemap:', $body);
        }
    }

    /**
     * A static public/robots.txt would shadow this route in every environment
     * — which is the failure the route exists to prevent, so it must not exist.
     */
    public function test_no_static_robots_file_shadows_the_route(): void
    {
        $this->assertFileDoesNotExist(
            public_path('robots.txt'),
            'a static robots.txt would be served instead of the environment-aware route'
        );
    }

    // ----------------------------------------------------------------- 8

    /**
     * EVERY page is noindex outside production, and no page may opt out.
     */
    public function test_the_whole_site_is_noindex_outside_production(): void
    {
        $this->seed(LegalPagesSeeder::class);

        $project = Project::factory()->published()->create();
        $article = Article::factory()->create();
        $service = Service::factory()->create();

        $urls = [
            '/', '/work', '/services', '/journal', '/archive', '/about', '/contact', '/privacy', '/terms',
            route('projects.show', $project),
            route('journal.show', $article),
            route('services.show', $service),
        ];

        foreach (['local', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            foreach ($urls as $url) {
                $html = $this->get($url)->assertOk()->getContent();

                $this->assertStringContainsString(
                    '<meta name="robots" content="noindex, nofollow">',
                    $html,
                    "{$url} is indexable in {$environment}"
                );
            }
        }
    }

    public function test_in_production_the_same_pages_are_indexable(): void
    {
        /*
         * Seeded BEFORE the environment switch: db:seed carries Laravel's own
         * ConfirmableTrait and stops to ask in production, which blows up on a
         * mocked console rather than telling you anything useful.
         */
        $this->seed(LegalPagesSeeder::class);

        $this->asProduction();

        foreach (['/', '/work', '/services', '/journal', '/about', '/contact', '/privacy'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                '<meta name="robots" content="index, follow">',
                $html,
                "{$url} is not indexable in production"
            );
        }

        // The confirmation page stays noindex even in production.
        $sent = $this->get('/contact/sent')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $sent);
    }

    // ----------------------------------------------------------------- 9

    /**
     * Pull every JSON-LD block out of a page and decode it.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        $this->assertNotEmpty($matches[1], "{$url} carries no structured data");

        return array_map(
            fn (string $json): array => json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR),
            $matches[1],
        );
    }

    /**
     * Recursively assert nothing empty was emitted.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoEmptyFields(array $data, string $context): void
    {
        foreach ($data as $key => $value) {
            $this->assertNotNull($value, "{$context}: {$key} is null");
            $this->assertNotSame('', $value, "{$context}: {$key} is an empty string");
            $this->assertNotSame([], $value, "{$context}: {$key} is an empty array");

            if (is_array($value)) {
                $this->assertNoEmptyFields($value, $context.'.'.$key);
            }
        }
    }

    public function test_every_structured_data_block_parses_and_holds_no_empty_field(): void
    {
        $this->seed(LegalPagesSeeder::class);

        $category = new ArticleCategory(['name' => 'Process']);
        $category->slug = 'process';
        $category->save();

        $project = Project::factory()->published()->create([
            'cover_media_id' => $this->world->media('landscape')->id,
        ]);
        $article = Article::factory()->create([
            'article_category_id' => $category->id,
            'featured_media_id' => $this->world->media('portrait')->id,
        ]);
        $service = Service::factory()->create([
            'cover_media_id' => $this->world->media('square')->id,
        ]);

        $pages = [
            '/' => ['ProfessionalService', 'WebSite'],
            // No trail on an index: Home → Services is two items and the
            // second is this page. See StructuredData::MIN_BREADCRUMBS.
            '/services' => ['ProfessionalService', 'WebSite'],
            route('services.show', $service) => ['ProfessionalService', 'WebSite', 'Service', 'BreadcrumbList'],
            route('projects.show', $project) => ['ProfessionalService', 'WebSite', 'CreativeWork', 'BreadcrumbList'],
            // Home → Journal → Process → the piece: four, and every step real.
            route('journal.show', $article) => ['ProfessionalService', 'WebSite', 'BlogPosting', 'BreadcrumbList'],
            '/privacy' => ['ProfessionalService', 'WebSite'],
        ];

        foreach ($pages as $url => $expectedTypes) {
            $blocks = $this->blocks($url);

            $types = array_map(static fn (array $block): string => $block['@type'], $blocks);

            $this->assertSame($expectedTypes, $types, "{$url} emitted the wrong set of blocks");

            foreach ($blocks as $block) {
                $this->assertSame('https://schema.org', $block['@context']);
                $this->assertNoEmptyFields($block, $url.' '.$block['@type']);
            }
        }
    }

    /**
     * D2, asserted directly: nothing we do not hold.
     *
     * These are the fields sites invent, and the ones a search engine
     * penalises hardest when they turn out to be false.
     */
    public function test_no_block_invents_a_field_we_do_not_hold(): void
    {
        $service = Service::factory()->quoted()->create();
        $article = Article::factory()->create(['article_category_id' => null, 'author_id' => null]);

        $forbidden = ['aggregateRating', 'review', 'ratingValue', 'reviewCount', 'priceRange'];

        foreach ([route('services.show', $service), route('journal.show', $article), '/'] as $url) {
            foreach ($this->blocks($url) as $block) {
                $flat = json_encode($block);

                foreach ($forbidden as $field) {
                    $this->assertStringNotContainsString(
                        '"'.$field.'"',
                        (string) $flat,
                        "{$url} invented {$field}"
                    );
                }
            }
        }

        // A service with no price emits no Offer at all, rather than £0.
        $serviceBlock = collect($this->blocks(route('services.show', $service)))
            ->firstWhere('@type', 'Service');

        $this->assertArrayNotHasKey('offers', $serviceBlock);

        // An article with no author and no category omits both.
        $articleBlock = collect($this->blocks(route('journal.show', $article)))
            ->firstWhere('@type', 'BlogPosting');

        Settings::set('attribution.name', '', 'string', 'attribution');
        Settings::flush();

        $articleBlock = collect($this->blocks(route('journal.show', $article)))
            ->firstWhere('@type', 'BlogPosting');

        $this->assertArrayNotHasKey('author', $articleBlock);
        $this->assertArrayNotHasKey('articleSection', $articleBlock);
    }

    /**
     * The photographs carry the credit and copyright held on their own media
     * record — not a site-wide default, and not the studio's name applied to
     * somebody else's photograph.
     */
    public function test_project_images_carry_their_own_credit_and_copyright(): void
    {
        $media = $this->world->media('landscape');
        $media->forceFill([
            'alt' => 'A shoreline under flat light',
            'caption' => 'Blyth, the second winter',
            'credit' => 'Mateusz Feliksik',
            'copyright' => 'https://example.test/photo/1',
        ])->save();

        $uncredited = $this->world->media('portrait');
        $uncredited->forceFill(['alt' => 'Uncredited frame', 'credit' => null, 'copyright' => null])->save();

        $project = Project::factory()->published()->create(['cover_media_id' => $media->id]);

        $block = collect($this->blocks(route('projects.show', $project)))
            ->firstWhere('@type', 'CreativeWork');

        $this->assertArrayHasKey('image', $block);

        $image = $block['image'][0];

        $this->assertSame('ImageObject', $image['@type']);
        $this->assertStringStartsWith('http', $image['contentUrl']);
        $this->assertSame('A shoreline under flat light', $image['description']);
        $this->assertSame('Blyth, the second winter', $image['caption']);
        $this->assertSame('Mateusz Feliksik', $image['creditText']);
        $this->assertSame('Mateusz Feliksik', $image['creator']['name']);
        $this->assertSame('https://example.test/photo/1', $image['copyrightNotice']);

        // And a frame with no credit emits none rather than borrowing one.
        $plain = Project::factory()->published()->create(['cover_media_id' => $uncredited->id]);

        $plainBlock = collect($this->blocks(route('projects.show', $plain)))
            ->firstWhere('@type', 'CreativeWork');

        $this->assertArrayNotHasKey('creditText', $plainBlock['image'][0]);
        $this->assertArrayNotHasKey('copyrightNotice', $plainBlock['image'][0]);
    }

    /**
     * A breadcrumb trail is emitted only where a hierarchy really exists. A
     * one-item "trail" is a page asserting its own existence twice.
     */
    public function test_a_breadcrumb_trail_needs_a_real_hierarchy(): void
    {
        $types = array_map(
            static fn (array $block): string => $block['@type'],
            $this->blocks('/')
        );

        $this->assertNotContains('BreadcrumbList', $types, 'the home page has no hierarchy above it');

        /*
         * Nor does an index. Home → Services is two items and the second is
         * the page you are on — a trail needs a step in between.
         */
        $indexTypes = array_map(
            static fn (array $block): string => $block['@type'],
            $this->blocks('/services')
        );

        $this->assertNotContains('BreadcrumbList', $indexTypes, 'an index page emitted a two-item trail');

        // And where one exists, the positions are sequential from 1.
        $service = Service::factory()->create();

        $trail = collect($this->blocks(route('services.show', $service)))
            ->firstWhere('@type', 'BreadcrumbList');

        $positions = array_column($trail['itemListElement'], 'position');

        $this->assertSame([1, 2, 3], $positions);
        $this->assertSame($service->name, $trail['itemListElement'][2]['name']);
    }

    /** A draft preview describes nothing to a machine. */
    public function test_a_preview_emits_no_creative_work(): void
    {
        $project = Project::factory()->create(['title' => 'Not finished']);

        $url = URL::temporarySignedRoute(
            'projects.preview',
            now()->addHour(),
            ['project' => $project->slug]
        );

        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        $types = array_map(
            static fn (string $json): string => json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR)['@type'],
            $matches[1],
        );

        $this->assertNotContains('CreativeWork', $types, 'a draft was described as a published work');
    }

    /**
     * A title carrying "</script>" cannot break out of a JSON-LD block.
     */
    public function test_structured_data_cannot_be_broken_out_of(): void
    {
        $service = Service::factory()->create([
            'name' => 'Closing </script><script>alert(1)</script> tags',
        ]);

        $html = $this->get(route('services.show', $service))->assertOk()->getContent();

        // Every script element on the page is a data block.
        $this->assertNoExecutableJavaScript($html, 'a service');
        $this->assertStringNotContainsString('</script><script>', $html);

        // And the JSON still parses, with the name intact inside it.
        $block = collect($this->blocks(route('services.show', $service)))->firstWhere('@type', 'Service');

        $this->assertStringContainsString('alert(1)', $block['name']);
    }
}
