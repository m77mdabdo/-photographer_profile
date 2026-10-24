<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use App\Services\Seo\SitemapBuilder;
use App\Support\Settings;
use App\Support\Sitemap;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class SitemapTest extends TestCase
{
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

    /**
     * Parse a sitemap document, failing loudly rather than returning null.
     */
    private function xml(string $url): \SimpleXMLElement
    {
        $body = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, $url.' is not valid XML: '.implode('; ', array_map(
            static fn ($error): string => trim($error->message),
            $errors
        )));

        return $xml;
    }

    /**
     * Every <loc> in a document, namespace-agnostic.
     *
     * @return list<string>
     */
    private function locations(string $url): array
    {
        $xml = $this->xml($url);

        $locations = [];

        foreach ($xml->children() as $child) {
            $locations[] = (string) $child->loc;
        }

        return $locations;
    }

    // ----------------------------------------------------------------- 3

    public function test_the_index_lists_every_segment_and_each_one_validates(): void
    {
        Project::factory()->published()->create();
        Article::factory()->create();
        Service::factory()->create();
        Page::factory()->create();

        Sitemap::forget();

        $index = $this->xml('/sitemap.xml');

        $this->assertSame('sitemapindex', $index->getName());

        $listed = $this->locations('/sitemap.xml');

        $this->assertCount(count(SitemapBuilder::SEGMENTS), $listed);

        foreach (SitemapBuilder::SEGMENTS as $segment) {
            $expected = route('sitemap.segment', $segment);

            $this->assertContains($expected, $listed, "the index omits the {$segment} segment");

            // And each one is itself a valid urlset.
            $document = $this->xml('/sitemap-'.$segment.'.xml');
            $this->assertSame('urlset', $document->getName(), "{$segment} is not a urlset");
        }
    }

    public function test_an_unknown_segment_is_a_404(): void
    {
        $this->get('/sitemap-nonsense.xml')->assertNotFound();
    }

    // ----------------------------------------------------------------- 4

    /**
     * NOTHING INVISIBLE APPEARS IN ANY SITEMAP.
     *
     * A sitemap is a set of promises. One that lists a draft, an archived
     * project or a noindex page tells a crawler this site does not know what
     * it publishes — and spends crawl budget on pages that cannot rank.
     *
     * Every segment is checked, not just the obvious one, because the failure
     * would be a query that forgot the scope somewhere.
     */
    public function test_nothing_invisible_ever_appears_in_a_sitemap(): void
    {
        // The things that must appear.
        $liveProject = Project::factory()->published()->create(['title' => 'Live project']);
        $liveArticle = Article::factory()->create(['title' => 'Live article']);
        $liveService = Service::factory()->create(['name' => 'Live service']);
        $livePage = Page::factory()->create(['title' => 'Live page']);

        // And the things that must not.
        $hidden = [
            Project::factory()->create(['title' => 'Draft project']),
            Project::factory()->archived()->create(['title' => 'Archived project']),
            Article::factory()->draft()->create(['title' => 'Draft article']),
            Article::factory()->scheduled()->create(['title' => 'Scheduled article']),
            Service::factory()->draft()->create(['name' => 'Draft service']),
            Page::factory()->draft()->create(['title' => 'Draft page']),
            Page::factory()->noindex()->create(['title' => 'Noindex page']),
        ];

        Sitemap::forget();

        $everyUrl = [];

        foreach (SitemapBuilder::SEGMENTS as $segment) {
            $everyUrl = array_merge($everyUrl, $this->locations('/sitemap-'.$segment.'.xml'));
        }

        // The visible ones are there.
        foreach ([
            route('projects.show', $liveProject),
            route('journal.show', $liveArticle),
            route('services.show', $liveService),
            route('pages.show', $livePage),
        ] as $expected) {
            $this->assertContains($expected, $everyUrl, "{$expected} is missing from the sitemaps");
        }

        // And nothing hidden is, under any address.
        foreach ($hidden as $model) {
            $path = '/'.$model->slug;

            foreach ($everyUrl as $url) {
                $this->assertStringNotContainsString(
                    $model->slug,
                    $url,
                    "a hidden record's slug ({$model->slug}) appears in a sitemap as {$url}"
                );
            }
        }

        /*
         * And no admin address, under any spelling. A sitemap that offers a
         * login page to a crawler is offering it to everything else too.
         */
        foreach ($everyUrl as $url) {
            $this->assertStringNotContainsString('/admin', $url);
            $this->assertStringNotContainsString('/login', $url);
            $this->assertStringNotContainsString('/contact/sent', $url);
        }
    }

    /**
     * A noindex page is reachable AND absent from the sitemap. The two
     * statements have to agree or the site is arguing with itself.
     */
    public function test_a_noindex_page_is_reachable_but_never_offered(): void
    {
        $page = Page::factory()->noindex()->create(['title' => 'Internal notice']);

        Sitemap::forget();

        $html = $this->get(route('pages.show', $page))->assertOk()->getContent();

        $this->assertStringContainsString('Internal notice', $html);
        $this->assertStringContainsString('noindex', $html);

        $this->assertNotContains(route('pages.show', $page), $this->locations('/sitemap-pages.xml'));
    }

    // ----------------------------------------------------------------- 5

    public function test_the_image_sitemap_carries_project_images_with_captions(): void
    {
        $media = $this->world->media('landscape');
        $media->forceFill([
            'alt' => 'A shoreline under flat northern light',
            'caption' => 'Blyth, the second winter',
        ])->save();

        $project = Project::factory()->published()->create([
            'title' => 'Salt Line',
            'cover_media_id' => $media->id,
        ]);

        Sitemap::forget();

        $body = $this->get('/sitemap-images.xml')->assertOk()->getContent();

        // The namespace is declared, or the image entries mean nothing.
        $this->assertStringContainsString(
            'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"',
            $body
        );

        $xml = $this->xml('/sitemap-images.xml');
        $images = $xml->children('image', true);

        // Find the entry for this project and read its images.
        $found = null;

        foreach ($xml->children() as $url) {
            if ((string) $url->loc === route('projects.show', $project)) {
                $found = $url;
                break;
            }
        }

        $this->assertNotNull($found, 'the project has no entry in the image sitemap');

        $entryImages = $found->children('http://www.google.com/schemas/sitemap-image/1.1');

        $this->assertGreaterThan(0, count($entryImages));

        $first = $entryImages[0];

        $this->assertStringStartsWith('http', (string) $first->loc);
        $this->assertSame('A shoreline under flat northern light', (string) $first->title);
        $this->assertSame('Blyth, the second winter', (string) $first->caption);
    }

    /**
     * A frame with no caption emits no caption, rather than repeating its own
     * filename into a field a search engine reads as the studio's words.
     */
    public function test_an_image_with_no_caption_emits_no_caption(): void
    {
        $media = $this->world->media('landscape');
        $media->forceFill(['alt' => 'A frame', 'caption' => null])->save();

        Project::factory()->published()->create(['cover_media_id' => $media->id]);

        Sitemap::forget();

        $body = $this->get('/sitemap-images.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<image:title>A frame</image:title>', $body);
        $this->assertStringNotContainsString('<image:caption></image:caption>', $body);
        $this->assertStringNotContainsString($media->original_filename, $body);
    }

    // ----------------------------------------------------------------- 6

    /**
     * LASTMOD REFLECTS CONTENT, NOT BUILD TIME.
     *
     * The single most common sitemap bug is a lastmod set to generation time,
     * which tells a crawler every page changed every time the cache expired.
     * After a few rounds of that a crawler stops believing lastmod at all —
     * and lastmod is the only field in a sitemap that does real work.
     */
    public function test_lastmod_reflects_content_modification_not_build_time(): void
    {
        $project = Project::factory()->published()->create();

        // Move its modification date firmly into the past.
        $modified = now()->subMonths(4)->startOfMinute();
        DB::table('projects')->where('id', $project->id)->update(['updated_at' => $modified]);

        Sitemap::forget();

        $xml = $this->xml('/sitemap-projects.xml');

        $lastmod = null;

        foreach ($xml->children() as $url) {
            if ((string) $url->loc === route('projects.show', $project)) {
                $lastmod = (string) $url->lastmod;
            }
        }

        $this->assertNotNull($lastmod);

        $this->assertSame(
            $modified->toAtomString(),
            $lastmod,
            'lastmod is not the record’s own updated_at'
        );

        // Explicitly NOT today, which is what a build-time lastmod would be.
        $this->assertStringNotContainsString(now()->toDateString(), $lastmod);
    }

    /**
     * And the cache does not freeze it: publishing drops the cache, so the
     * next fetch reflects the new state.
     */
    public function test_publishing_invalidates_the_cached_sitemap(): void
    {
        $this->get('/sitemap-journal.xml')->assertOk();

        $article = Article::factory()->create(['title' => 'Written after the first fetch']);

        // No manual cache clear here: the model event is the whole point.
        $this->assertContains(
            route('journal.show', $article),
            $this->locations('/sitemap-journal.xml'),
            'a newly published article did not reach the cached sitemap'
        );

        // And unpublishing takes it away again.
        $article->forceFill(['status' => PublishStatus::Draft])->save();

        $this->assertNotContains(
            route('journal.show', $article),
            $this->locations('/sitemap-journal.xml'),
            'an unpublished article stayed in the cached sitemap'
        );
    }

    /**
     * The index's own lastmod per segment is the newest inside that segment,
     * so a crawler reading only the index knows what to re-fetch.
     */
    public function test_the_index_lastmod_is_the_newest_entry_in_each_segment(): void
    {
        $older = Article::factory()->create();
        $newer = Article::factory()->create();

        DB::table('articles')->where('id', $older->id)->update(['updated_at' => now()->subYear()]);
        DB::table('articles')->where('id', $newer->id)->update(['updated_at' => now()->subDay()->startOfMinute()]);

        Sitemap::forget();

        $index = $this->xml('/sitemap.xml');

        foreach ($index->children() as $entry) {
            if ((string) $entry->loc === route('sitemap.segment', 'journal')) {
                $this->assertSame(
                    $newer->fresh()->updated_at->toAtomString(),
                    (string) $entry->lastmod
                );

                return;
            }
        }

        $this->fail('the journal segment is not in the index');
    }
}
