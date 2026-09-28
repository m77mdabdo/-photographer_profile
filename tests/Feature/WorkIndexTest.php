<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class WorkIndexTest extends TestCase
{
    use \Tests\Support\AssertsNoJavaScript;
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();

        $this->world = new DemoWorld();
    }

    private function fill(int $published = 14): void
    {
        for ($i = 0; $i < $published; $i++) {
            $this->world->story(
                'series-'.$i,
                DemoWorld::simpleScore(2),
                $i % 2 === 0 ? ['landscape'] : ['portrait'],
                orientation: $i % 3 === 0 ? 'portrait' : 'landscape',
            );
        }
    }

    // ----------------------------------------------------------------- 1

    public function test_the_index_renders_and_paginates(): void
    {
        $this->fill(14);

        $html = $this->get('/work')->assertOk()->getContent();

        // Twelve to a page, so fourteen gives two pages.
        $this->assertSame(12, substr_count($html, 'class="work__link"'));
        $this->assertStringContainsString('class="pager"', $html);
        $this->assertStringContainsString('rel="next"', $html);
        $this->assertStringNotContainsString('rel="prev"', $html);

        $second = $this->get('/work?page=2')->assertOk()->getContent();

        $this->assertSame(2, substr_count($second, 'class="work__link"'));
        $this->assertStringContainsString('rel="prev"', $second);
        $this->assertStringNotContainsString('rel="next"', $second);

        // The planner composed the page, rather than the page being a list.
        $this->assertStringContainsString('--span:', $html);
        $this->assertStringContainsString('--drop:', $html);
        $this->assertStringContainsString('works__item--feature', $html);
    }

    public function test_the_index_excludes_drafts_and_archived_work(): void
    {
        $this->fill(3);

        $this->world->story('secret-draft', DemoWorld::simpleScore(1), status: PublishStatus::Draft);

        $archived = $this->world->story('retired-work', DemoWorld::simpleScore(1));
        $archived->forceFill(['status' => PublishStatus::Archived])->save();

        $scheduled = $this->world->story('next-week', DemoWorld::simpleScore(1));
        $scheduled->forceFill(['published_at' => now()->addWeek()])->save();

        $html = $this->get('/work')->assertOk()->getContent();

        $this->assertStringNotContainsString('Secret Draft', $html);
        $this->assertStringNotContainsString('Retired Work', $html);
        $this->assertStringNotContainsString('Next Week', $html);
        $this->assertStringContainsString('Series 0', $html);

        // And the count in the header is the published count, not the total.
        $this->assertSame(3, Project::query()->published()->count());
        $this->assertStringContainsString('3 series', $html);
    }

    public function test_the_first_cover_is_the_lcp_and_the_rest_are_lazy(): void
    {
        $this->fill(6);

        $html = $this->get('/work')->assertOk()->getContent();

        // Exactly one high-priority image on the page.
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));

        // And it carries no loading attribute at all.
        $first = substr($html, (int) strpos($html, 'class="work__frame"'));
        $img = substr($first, (int) strpos($first, '<img'));
        $img = substr($img, 0, (int) strpos($img, '>'));

        $this->assertStringContainsString('fetchpriority="high"', $img);
        $this->assertStringNotContainsString('loading=', $img);

        // Everything below it is lazy: one eager image, five lazy ones.
        $this->assertSame(5, substr_count($html, 'loading="lazy"'));
    }

    public function test_the_index_ships_no_javascript(): void
    {
        $this->fill(4);

        foreach (['/work', '/work?page=1'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertNoExecutableJavaScript($html);
            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringNotContainsString('wire:', $html);
        }
    }

    public function test_the_empty_state_reads_as_a_decision(): void
    {
        $html = $this->get('/work')->assertOk()->getContent();

        $this->assertStringContainsString('work-empty', $html);
        $this->assertStringContainsString('Nothing published yet', $html);
        $this->assertStringContainsString('Work appears here as it is finished', $html);

        // No apology, no broken-looking shell.
        $this->assertStringNotContainsString('class="pager"', $html);
        $this->assertStringNotContainsString('works__list', $html);
    }

    // ----------------------------------------------------------------- 2

    public function test_a_category_page_filters_and_404s_for_an_unknown_slug(): void
    {
        $this->fill(6);

        $architecture = $this->world->category('architecture');

        $only = $this->world->story('concrete-season', DemoWorld::simpleScore(2), ['architecture']);

        $html = $this->get('/work/category/architecture')->assertOk()->getContent();

        $this->assertStringContainsString($only->title, $html);
        $this->assertStringNotContainsString('Series 1', $html);
        $this->assertSame(1, substr_count($html, 'class="work__link"'));

        $this->get('/work/category/does-not-exist')->assertNotFound();

        // The literal segment must not be swallowed by the project wildcard.
        $this->assertSame('architecture', $architecture->slug);
        $this->get('/work/category')->assertNotFound();
    }

    public function test_category_pages_canonicalise_to_themselves(): void
    {
        $this->fill(2);

        for ($i = 0; $i < 14; $i++) {
            $this->world->story('arch-'.$i, DemoWorld::simpleScore(1), ['architecture']);
        }

        // Page one of a category points at the clean category URL, NOT /work:
        // collapsing them would throw the landing page away.
        $first = $this->get('/work/category/architecture')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="'.route('work.category', 'architecture').'">',
            $first
        );

        /*
         * IN PRODUCTION, deliberately.
         *
         * Outside production every page is noindex, nofollow — that is the
         * staging-leak guard in SeoResolver and it overrides every page's own
         * preference. The distinction this test is about only exists where
         * indexing is correct, so it is asserted there.
         */
        $this->app->detectEnvironment(fn (): string => 'production');

        $first = $this->get('/work/category/architecture')->assertOk()->getContent();
        $this->assertStringNotContainsString('noindex', $first);

        /*
         * Page two canonicalises to ITSELF and is noindex, follow. Pointing
         * every page at page one is the classic mistake and tells a crawler
         * the projects only reachable on page two do not exist.
         */
        $second = $this->get('/work/category/architecture?page=2')->assertOk()->getContent();

        $this->assertStringContainsString('page=2', $this->canonicalOf($second));
        $this->assertStringContainsString('noindex, follow', $second);
    }

    public function test_the_filter_only_offers_categories_that_have_published_work(): void
    {
        $this->fill(2);

        $this->world->category('fashion');   // exists, but nothing published in it

        $html = $this->get('/work')->assertOk()->getContent();

        $this->assertStringContainsString(route('work.category', 'landscape'), $html);
        $this->assertStringNotContainsString(route('work.category', 'fashion'), $html);
    }

    // ----------------------------------------------------------------- 5, 6

    public function test_a_long_story_renders_every_block_type(): void
    {
        $project = $this->world->story('the-long-one', DemoWorld::fullScore(), ['editorial']);

        $this->assertSame(25, $project->blocks->sum(fn ($b) => $b->blockMedia->count()));

        $html = $this->get('/work/the-long-one')->assertOk()->getContent();

        /*
         * The root classes are semantic groupings rather than one per type —
         * a landscape pair and a portrait pair are both block--pair, and both
         * split blocks are block--split — so the structural markers are
         * asserted by class and the individual types by the content only they
         * render. That is the stronger check anyway: a class can appear on a
         * block that rendered nothing.
         */
        foreach (['block--full-width-image', 'block--contained-image', 'block--text',
                  'block--quote', 'block--grid', 'block--pair', 'block--split',
                  'block--spacer', 'block--gallery-strip', 'block--credits'] as $marker) {
            $this->assertStringContainsString($marker, $html, "missing {$marker}");
        }

        // Two landscape pairs and one portrait pair in the score.
        $this->assertSame(3, substr_count($html, 'block--pair'), 'two landscape pairs and a portrait pair');
        $this->assertSame(2, substr_count($html, 'block--split'), 'image-text and text-image');

        foreach (['An opening frame.', 'A heading', 'A pair.', 'A line worth pulling out.',
                  'Three at once.', 'Beside the picture', 'The other way round',
                  'The full run.', 'Made with'] as $content) {
            $this->assertStringContainsString($content, $html, "block content missing: {$content}");
        }

        // Every frame is on the page, and none of them lost its dimensions.
        $this->assertSame(25, substr_count($html, '<img'));
        $this->assertSame(25, substr_count($html, 'width="'));
        $this->assertNoExecutableJavaScript($html);
    }

    public function test_the_short_story_holds_at_three_frames(): void
    {
        $this->world->story('three-rooms', DemoWorld::shortScore(), ['editorial']);

        $html = $this->get('/work/three-rooms')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<img'));

        // The templates hold: a heading, the three frames, the credits block.
        $this->assertStringContainsString('block--full-width-image', $html);
        $this->assertStringContainsString('block--split', $html);
        $this->assertStringContainsString('block--contained-image', $html);
        $this->assertStringContainsString('block--credits', $html);
        $this->assertStringContainsString('Three is the series', $html);

        // Still exactly one LCP image, still lazy below it.
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));
        $this->assertSame(2, substr_count($html, 'loading="lazy"'));
    }

    // ----------------------------------------------------------------- 10

    public function test_the_query_counts_are_flat(): void
    {
        $this->fill(14);
        $this->world->story('the-long-one', DemoWorld::fullScore(), ['editorial']);
        $this->world->story('arch-one', DemoWorld::simpleScore(2), ['architecture']);

        $counts = [];
        $queries = [];

        /*
         * Registered ONCE, outside the loop. DB::listen stacks: a listener
         * added per iteration means the second page records every query twice
         * and the third records it three times, which reads exactly like an
         * N+1 and is not one.
         */
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        /*
         * WARM FIRST.
         *
         * Three site-wide maps are loaded once per process and cached
         * forever: the settings map, the redirect map and the footer's legal
         * links. Measuring the very first request of a process counts all
         * three, which is a number no running site ever pays twice — so the
         * measurement is taken on the second request, which is what every
         * visitor after the first actually costs.
         */
        foreach ([
            '/work' => 'index',
            '/work?page=2' => 'index page 2',
            '/work/category/architecture' => 'category',
            '/work/the-long-one' => '25-image story',
        ] as $url => $label) {
            $this->get($url);

            $queries = [];

            $this->get($url)->assertOk();

            $counts[$label] = count($queries);

            /*
             * The N+1 test that matters: no query may be issued more than
             * twice with the same shape. A page that loads a cover per
             * project, or a variant set per image, shows up here as the same
             * SELECT repeated a dozen times.
             */
            $shapes = array_count_values($queries);
            arsort($shapes);
            $worst = array_key_first($shapes);

            $this->assertLessThanOrEqual(
                2,
                $shapes[$worst],
                $label.' repeats a query '.$shapes[$worst].' times, which is an N+1: '.$worst
            );
        }

        // Pinned. These are flat in the number of projects and images.
        $this->assertSame(
            ['index' => 6, 'index page 2' => 6, 'category' => 7, '25-image story' => 9],
            $counts,
            'query counts moved: '.json_encode($counts)
        );
    }

    private function canonicalOf(string $html): string
    {
        preg_match('/<link rel="canonical" href="([^"]+)">/', $html, $found);

        return $found[1] ?? '';
    }
}
