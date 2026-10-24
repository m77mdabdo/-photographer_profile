<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Models\Media;
use App\Models\Project;
use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsNoJavaScript;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use AssertsNoJavaScript;
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

        Settings::flush();
        $this->seed(SettingsSeeder::class);
        Settings::flush();

        $this->world = new DemoWorld;
    }

    private function fill(): void
    {
        // Two series in different categories and different years, plus a
        // draft whose frames must never reach the sheet.
        $this->world->story('salt-line', DemoWorld::simpleScore(8), ['landscape'], year: 2025);
        $this->world->story('concrete-season', DemoWorld::simpleScore(6), ['architecture'], year: 2024, orientation: 'portrait');
        $this->world->story('unpublished', DemoWorld::simpleScore(5), ['landscape'], year: 2025, status: PublishStatus::Draft);
    }

    // ----------------------------------------------------------------- 3

    public function test_the_sheet_shows_frames_from_published_work_only(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        // Eight plus six, and none of the draft's five.
        $this->assertSame(14, substr_count($html, 'class="sheet-frame"'));
        $this->assertStringContainsString('14 frames', $html);
        $this->assertStringContainsString('Salt Line', $html);
        $this->assertStringContainsString('Concrete Season', $html);
        $this->assertStringNotContainsString('Unpublished', $html);
    }

    public function test_it_filters_by_category_from_the_url(): void
    {
        $this->fill();

        $html = $this->get('/archive?category=architecture')->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'class="sheet-frame"'));
        $this->assertStringContainsString('Concrete Season', $html);
        $this->assertStringNotContainsString('Salt Line', $html);

        // The filter is a real address, and the current one is marked.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*category=architecture"[^>]*class="[^"]*is-current/',
            $html
        );
    }

    public function test_it_filters_by_year_from_the_url(): void
    {
        $this->fill();

        $html = $this->get('/archive?year=2024')->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'class="sheet-frame"'));
        $this->assertStringContainsString('Concrete Season', $html);
        $this->assertStringNotContainsString('Salt Line', $html);
    }

    public function test_the_two_filters_combine(): void
    {
        $this->fill();

        // A combination with nothing in it must be empty, not unfiltered.
        $empty = $this->get('/archive?category=architecture&year=2025')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="sheet-frame"', $empty);
        $this->assertStringContainsString('No frames under that filter', $empty);

        // And a combination that does match keeps both.
        $both = $this->get('/archive?category=architecture&year=2024')->assertOk()->getContent();
        $this->assertSame(6, substr_count($both, 'class="sheet-frame"'));

        // Each filter link preserves the other.
        $this->assertStringContainsString('category=architecture&amp;year=2024', $both);
    }

    public function test_a_nonsense_filter_falls_back_rather_than_erroring(): void
    {
        $this->fill();

        // An unknown category and a non-numeric year are ignored, not fatal:
        // a hand-edited URL must not be able to 500 a public page.
        $html = $this->get('/archive?category=does-not-exist&year=banana')->assertOk()->getContent();

        $this->assertSame(14, substr_count($html, 'class="sheet-frame"'));
    }

    // ----------------------------------------------------------------- 4

    /**
     * The rule most contact sheets break.
     */
    public function test_every_frame_carries_its_metadata_without_hover(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        // A caption element per frame, in the markup, not in a title attribute.
        $this->assertSame(14, substr_count($html, 'sheet-frame__caption'));
        $this->assertSame(14, substr_count($html, 'sheet-frame__number'));
        $this->assertSame(14, substr_count($html, 'sheet-frame__series'));

        // Frame numbers are printed, like a film edge.
        $this->assertStringContainsString('>001<', $html);
        $this->assertStringContainsString('>014<', $html);

        // Dimensions are stated, not implied.
        $this->assertStringContainsString('4000&times;2667', $html);

        /*
         * Nothing reveals metadata on hover. A :hover rule that changes colour
         * is fine; one that changes opacity, visibility, display or height is
         * how information ends up unreachable on a touch screen.
         */
        $css = (string) file_get_contents(resource_path('css/pages/archive.css'));

        preg_match_all('/\.sheet-frame[^{]*:hover[^{]*\{([^}]*)\}/s', $css, $rules);

        foreach ($rules[1] ?? [] as $body) {
            foreach (['opacity', 'visibility', 'display', 'height', 'transform'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $body,
                    "a hover rule changes {$forbidden}, which hides information from touch and keyboard"
                );
            }
        }

        // And no caption is delivered as a tooltip.
        $this->assertDoesNotMatchRegularExpression('/<(a|span|li)[^>]*class="sheet-frame[^"]*"[^>]*title=/', $html);
    }

    public function test_each_frame_links_to_its_series(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        $this->assertSame(14, substr_count($html, 'class="sheet-frame__link"'));
        $this->assertStringContainsString(route('projects.show', 'salt-line'), $html);
        $this->assertStringContainsString(route('projects.show', 'concrete-season'), $html);
    }

    // ----------------------------------------------------------------- 7, 8

    public function test_the_first_row_loads_eagerly_and_the_rest_do_not(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        // One LCP.
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));

        $firstImg = substr($html, (int) strpos($html, 'class="sheet-frame"'));
        $firstImg = substr($firstImg, (int) strpos($firstImg, '<img'));
        $firstImg = substr($firstImg, 0, (int) strpos($firstImg, '>'));

        $this->assertStringContainsString('fetchpriority="high"', $firstImg);
        $this->assertStringNotContainsString('loading=', $firstImg);

        /*
         * The rest of the first row is eager but NOT high priority — six
         * competing high-priority images tell the browser nothing. Everything
         * from the seventh frame on is lazy.
         */
        $this->assertSame(5, substr_count($html, 'loading="eager"'));
        $this->assertSame(8, substr_count($html, 'loading="lazy"'));
    }

    public function test_the_sheet_uses_small_renditions(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        /*
         * The sizes attribute is what decides which rendition a browser pulls.
         * At contact-sheet scale a frame is never more than about a fifth of
         * the viewport, and saying so is the difference between the 480px
         * rendition and the 1920px one on every frame on the page.
         */
        $this->assertStringContainsString('sizes="(max-width: 700px) 45vw, (max-width: 1100px) 24vw, 17vw"', $html);
    }

    // ----------------------------------------------------------------- 9

    public function test_the_archive_ships_no_javascript(): void
    {
        $this->fill();

        foreach (['/archive', '/archive?category=architecture', '/archive?year=2024'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertNoExecutableJavaScript($html, $url);
            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringNotContainsString('wire:', $html);
        }
    }

    public function test_a_filtered_sheet_is_followed_but_not_indexed(): void
    {
        $this->fill();

        /*
         * IN PRODUCTION, deliberately.
         *
         * Outside production every page is noindex, nofollow — that is the
         * staging-leak guard in SeoResolver and it overrides every page's own
         * preference. The distinction this test is about only exists where
         * indexing is correct, so it is asserted there.
         */
        $this->app->detectEnvironment(fn (): string => 'production');

        $plain = $this->get('/archive')->assertOk()->getContent();
        $this->assertStringNotContainsString('noindex', $plain);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('archive.index').'">', $plain);

        /*
         * Every combination of category and year is a way of looking at the
         * same frames rather than a body of work with its own address, and
         * letting them all into an index generates thousands of near
         * duplicates. Unlike a /work category, which is a landing page.
         */
        $filtered = $this->get('/archive?category=architecture')->assertOk()->getContent();
        $this->assertStringContainsString('noindex, follow', $filtered);
    }

    // ----------------------------------------------------------------- 10

    public function test_the_sheet_is_flat_in_the_number_of_frames(): void
    {
        // A full roll plus one, so pagination is exercised.
        $this->world->story('long-roll', DemoWorld::simpleScore(40), ['landscape'], year: 2025);

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        /*
         * Warmed first. The settings map, the redirect map and the footer's
         * legal links are read once per process and cached forever, so
         * measuring the first request of a process counts three one-time
         * costs no running site pays twice.
         */
        $this->get('/archive');

        $queries = [];
        $this->get('/archive')->assertOk();
        $full = count($queries);

        $queries = [];
        $this->get('/archive?category=landscape&year=2025')->assertOk();
        $filtered = count($queries);

        /*
         * Seven and eight, not eight and eight.
         *
         * The unfiltered sheet is one query cheaper because it needs no
         * category lookup to resolve the filter — the filtered view has to
         * find the category before it can count anything. Both are flat in
         * the number of frames, which is what this test is actually for.
         */
        $this->assertSame(
            ['sheet' => 7, 'filtered sheet' => 8],
            ['sheet' => $full, 'filtered sheet' => $filtered],
            'archive query counts moved: '.json_encode(['sheet' => $full, 'filtered' => $filtered])
        );

        // Thirty-six to a page.
        $html = $this->get('/archive')->getContent();
        $this->assertSame(36, substr_count($html, 'class="sheet-frame"'));
        $this->assertStringContainsString('sheet 1 of 2', $html);
    }

    public function test_no_query_is_repeated_per_frame(): void
    {
        $this->world->story('long-roll', DemoWorld::simpleScore(30), ['landscape']);

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $this->get('/archive')->assertOk();

        $shapes = array_count_values($queries);
        arsort($shapes);
        $worst = array_key_first($shapes);

        $this->assertLessThanOrEqual(
            2,
            $shapes[$worst],
            'a query repeats '.$shapes[$worst].' times across 30 frames, which is an N+1: '.$worst
        );
    }

    // ----------------------------------------------------------------- 4, 5

    /**
     * The seeder may borrow from its own content and nothing else.
     *
     * Three synthetic gradients generated in an early task — no demo_set, no
     * credit, not photographs — were pulled into a published gallery strip by
     * the fallback pool and became the first three frames of the archive. The
     * fallback is now restricted to is_demo media; this asserts the property
     * rather than the absence of those three particular rows.
     */
    public function test_the_story_seeder_never_reaches_into_real_media(): void
    {
        /*
         * EVERY seeder, not just the one that was caught.
         *
         * The property is: a seeder may select demo media and nothing else.
         * Asserting it on DemoStoriesSeeder alone would have left
         * DemoContentSeeder — which is where the leak actually originated and
         * which selected media with no condition at all — free to do it again
         * the next time anybody ran it.
         */
        $seeders = glob(base_path('database/seeders/*.php')) ?: [];

        $this->assertNotEmpty($seeders);

        $selections = 0;

        foreach ($seeders as $file) {
            $source = (string) file_get_contents($file);

            // Every Media query chain that ends in a fetch.
            /*
             * [^;]*? rather than .*? — a chain cannot span a statement. With
             * .*? the scan ran past an exists() call in markInheritedDemoWork,
             * which deliberately looks for NON-demo media in order to decide
             * whether a project is entirely demo, and joined it to the next
             * unrelated query.
             */
            preg_match_all('/Media::query\(\)([^;]*?)->(get|first|pluck|cursor)\(/s', $source, $chains);

            foreach ($chains[1] as $chain) {
                $selections++;

                $this->assertStringContainsString(
                    "->where('is_demo', true)",
                    $chain,
                    basename($file).' selects media without restricting to demo content: '
                    .trim(preg_replace('/\s+/', ' ', $chain) ?? '')
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $selections,
            'expected at least three media selections across the seeders; the scan found '.$selections
        );
    }

    public function test_no_project_is_left_without_a_cover(): void
    {
        $this->fill();

        // Whatever else happens to the library, an index of covers cannot
        // have a hole in it.
        $this->assertSame(
            0,
            Project::query()->published()->whereNull('cover_media_id')->count(),
            'a published project has no cover'
        );

        $html = $this->get('/work')->assertOk()->getContent();

        $this->assertSame(
            substr_count($html, 'class="work__link"'),
            substr_count($html, 'class="work__frame"'),
            'a work card rendered without its frame'
        );
    }

    public function test_the_sheet_holds_only_real_photographs(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        /*
         * ───────────────────────────────────────────────────────────────────
         * TASK 36c A2 — THIS TEST ASSERTED NOTHING, AND THE REASON MATTERED
         * MORE THAN THE FIX.
         *
         * It hunted for media with no credit — the signature of the three
         * synthetic gradients that once leaked into a published gallery —
         * and asserted their uuids were absent from the sheet. Planting
         * $this->fail() in the loop showed it never ran: BOTH fixture
         * builders always set a credit (DemoWorld writes 'A Photographer',
         * MediaFactory writes a faker name), so the condition it was looking
         * for could not exist in its own fixture. Vacuous since written.
         *
         * AND THE CLAIM WAS NOT ONE THE CODE MAKES. ArchiveController selects
         * the media used by a PUBLISHED project and filters on nothing else —
         * no credit condition anywhere. An uncredited image attached to a
         * published story would reach the sheet, so manufacturing the missing
         * fixture would have produced a failing test rather than a passing
         * one, and the failure would have been the test's claim, not a bug.
         *
         * What the fix actually established — asserted by
         * test_the_story_seeder_never_reaches_into_real_media — is that a
         * seeder may only select is_demo media, so a synthetic image never
         * gets attached in the first place.
         *
         * So this now asserts the reachable half of that, over a population
         * that EXISTS: an uncredited, non-demo image in the library is not
         * among the frames a published story uses, and does not appear on the
         * sheet. The offender is created here deliberately, so the loop can
         * never be empty again, and the count is asserted before the loop.
         * ───────────────────────────────────────────────────────────────────
         */
        $synthetic = Media::factory()->real()->create([
            'credit' => null,
            'alt' => null,
        ]);

        $uncredited = Media::query()
            ->where(fn ($q) => $q->whereNull('credit')->orWhere('credit', ''))
            ->pluck('id');

        $this->assertContains(
            $synthetic->id,
            $uncredited->all(),
            'the deliberately uncredited fixture was not found, so the loop below is empty again'
        );

        $leaked = [];

        foreach ($uncredited as $id) {
            $media = Media::find($id);

            $this->assertNotNull($media);

            if (str_contains($html, (string) $media->uuid)) {
                $leaked[] = $id;
            }
        }

        $this->assertSame(
            [],
            $leaked,
            'an uncredited image reached the public archive: media '.implode(', ', $leaked)
        );
    }

    // ----------------------------------------------------------------- 11

    public function test_every_frame_on_the_sheet_has_an_alt_and_a_credit(): void
    {
        $this->fill();

        $html = $this->get('/archive')->assertOk()->getContent();

        // No empty alt anywhere: these are photographs, not decoration.
        $this->assertStringNotContainsString('alt=""', $html);

        preg_match_all('/<img[^>]*>/', $html, $images);

        $this->assertNotEmpty($images[0]);

        foreach ($images[0] as $img) {
            $this->assertMatchesRegularExpression('/alt="[^"]+"/', $img, 'an image has no alt: '.$img);
        }

        foreach (Media::query()->where('is_demo', true)->get() as $media) {
            $this->assertNotEmpty($media->alt, "media {$media->id} has no alt");
            $this->assertNotEmpty($media->credit, "media {$media->id} has no credit");
        }
    }
}
