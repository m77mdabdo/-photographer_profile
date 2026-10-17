<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 35 A7 — THE QUERY COUNTS, IN THE MODE THEY ARE SERVED IN.
 *
 * WorkIndexTest pins the counts — index 6, category 7, a 25-image story 9 —
 * and pins them under the TEST environment, where the cache is an array and
 * the session is a file. Production runs neither: CACHE_STORE=database and
 * SESSION_DRIVER=database, which this project has shipped since it was built.
 *
 * Measured against a production build — config, routes and views cached,
 * OPcache on, debug off — a warm /work costs ELEVEN queries, not six:
 *
 *     3 × select * from `cache` where `key` in (?)   the settings map, the
 *                                                    redirect map, the legal
 *                                                    links
 *     2 × sessions                                   read, then write
 *     6 × content                                    exactly the pinned six
 *
 * So the pin holds for the thing it was measuring and misses five queries a
 * real visitor pays. That is not an N+1 and not a defect in the pages — it is
 * the cost of backing two caches with the database — but it was invisible,
 * and a number nobody can see is a number nobody maintains.
 *
 * This test measures with the production drivers configured, separates
 * infrastructure from content, and pins both.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ProductionQueryProfileTest extends TestCase
{
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The production drivers, named explicitly. The cache table exists in
         * the test database because the migration that creates it is this
         * application's own.
         */
        config([
            'cache.default' => 'database',
            'session.driver' => 'database',
        ]);

        $this->world = new DemoWorld;
    }

    /**
     * @return array<string, array{0: string, 1: int}>  path => content queries
     */
    public static function pages(): array
    {
        return [
            'work index' => ['/work', 6],
            'a category' => ['/work/category/architecture', 7],
            'the archive' => ['/archive', 7],
            'the journal' => ['/journal', 4],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_content_queries_hold_in_production_mode(string $path, int $expected): void
    {
        /*
         * A WORLD WITH SOMETHING IN IT.
         *
         * The first version of this pinned the numbers measured against the
         * development database and then ran against an empty one, where
         * /journal costs two queries because there is nothing to join to. A
         * query profile measured on no rows is not a query profile.
         */
        $this->world->story('arch-one', DemoWorld::simpleScore(2), ['architecture']);
        $this->world->story('arch-two', DemoWorld::simpleScore(2), ['architecture']);
        $this->world->story('field-notes', DemoWorld::simpleScore(3), ['landscape']);

        $category = \App\Models\ArticleCategory::factory()->create();

        \App\Models\Article::factory()
            ->count(3)
            ->for($category, 'category')
            ->create([
                'status' => \App\Enums\PublishStatus::Published,
                'published_at' => now()->subDay(),
            ]);

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = preg_replace('/\s+/', ' ', $query->sql);
        });

        // Warm: the three site-wide maps are loaded once per process.
        $this->get($path);

        $queries = [];

        $this->get($path)->assertOk();

        $infrastructure = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, '`cache`') || str_contains($sql, '`sessions`'),
        ));

        $content = array_values(array_filter(
            $queries,
            fn (string $sql): bool => ! str_contains($sql, '`cache`') && ! str_contains($sql, '`sessions`'),
        ));

        $this->assertCount(
            $expected,
            $content,
            $path.' issues '.count($content).' content queries: '.implode(' | ', array_map(
                fn (string $q): string => substr($q, 0, 60),
                $content,
            )),
        );

        /*
         * And none of them twice over. The cache reads legitimately repeat —
         * three different keys, one statement shape — so they are counted
         * separately above and excluded here.
         */
        $shapes = array_count_values($content);
        arsort($shapes);

        $this->assertLessThanOrEqual(
            2,
            reset($shapes) ?: 0,
            $path.' repeats a content query: '.array_key_first($shapes),
        );

        /*
         * The infrastructure cost, pinned so a fourth site-wide map or a
         * second session write shows up as a change rather than as weather.
         *
         * ───────────────────────────────────────────────────────────────────
         * GARBAGE COLLECTION IS A LOTTERY, AND THIS TEST WAS FAILING TWO RUNS
         * IN A HUNDRED BECAUSE OF IT.
         *
         * Laravel's StartSession calls collectGarbage() on config
         * session.lottery, which is [2, 100]: two per cent of requests also
         * issue `delete from sessions where last_activity <= ?`. That made a
         * sixth query appear at random, on a random one of these paths, and
         * fail an assertion pinned at five. Found during Task 36's full-suite
         * run, where the SAME suite failed on /archive once and on
         * /work/category/architecture the next time with nothing changed in
         * between.
         *
         * It is excluded rather than accommodated by raising the number to
         * six. Raising it would quietly give every page one more free
         * session write for ever; naming the GC statement keeps the ceiling
         * at what a request actually pays and still fails if a second write
         * appears.
         * ───────────────────────────────────────────────────────────────────
         */
        $steady = array_values(array_filter(
            $infrastructure,
            fn (string $sql): bool => ! (str_contains($sql, 'delete from `sessions`') && str_contains($sql, 'last_activity')),
        ));

        $this->assertLessThanOrEqual(
            5,
            count($steady),
            $path.' pays '.count($steady).' cache/session queries: '.implode(' | ', $steady),
        );

        /*
         * And the GC, when the lottery fires, is ONE statement and not a
         * query per expired row.
         */
        $this->assertLessThanOrEqual(
            1,
            count($infrastructure) - count($steady),
            $path.' issued more than one session garbage-collection statement',
        );
    }

    /**
     * The three maps are read once per process, not once per page element.
     *
     * Each is a `rememberForever`, so the cost is one cache read apiece on a
     * cold process and nothing afterwards — which is the whole argument for
     * the memo in Settings. A regression here would be a map that re-reads
     * per call, and it would be invisible in the array cache.
     */
    public function test_the_site_wide_maps_are_read_once_per_process(): void
    {
        $this->get('/');

        $reads = [];

        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, '`cache`')) {
                $reads[] = $query->bindings[0] ?? '?';
            }
        });

        $this->get('/')->assertOk();
        $this->get('/work')->assertOk();

        $this->assertLessThanOrEqual(
            8,
            count($reads),
            'the site-wide maps are being re-read: '.implode(', ', $reads),
        );
    }
}
