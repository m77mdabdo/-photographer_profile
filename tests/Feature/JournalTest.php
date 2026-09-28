<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class JournalTest extends TestCase
{
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

    private function category(string $name, string $slug): ArticleCategory
    {
        $category = new ArticleCategory(['name' => $name, 'description' => 'About '.$name.'.']);
        $category->slug = $slug;
        $category->save();

        return $category;
    }

    // ----------------------------------------------------------------- 7

    public function test_the_index_an_article_and_a_category_all_render(): void
    {
        $category = $this->category('Process', 'process');

        $article = Article::factory()->create([
            'title' => 'The edit is the work',
            'article_category_id' => $category->id,
        ]);

        // --- the index ------------------------------------------------------
        $index = $this->get('/journal')->assertOk()->getContent();

        $this->assertStringContainsString('The edit is the work', $index);
        $this->assertStringContainsString(route('journal.show', $article), $index);
        $this->assertStringContainsString(e((string) Settings::get('journal.heading')), $index);

        // --- the article ----------------------------------------------------
        $show = $this->get(route('journal.show', $article))->assertOk()->getContent();

        $this->assertStringContainsString('The edit is the work', $show);

        // --- the category ---------------------------------------------------
        $landing = $this->get(route('journal.category', $category))->assertOk()->getContent();

        $this->assertStringContainsString('Process', $landing);
        $this->assertStringContainsString('The edit is the work', $landing);
    }

    public function test_drafts_and_scheduled_pieces_are_404_not_403(): void
    {
        $draft = Article::factory()->draft()->create(['title' => 'Not finished']);
        $scheduled = Article::factory()->scheduled()->create(['title' => 'Next week']);

        foreach ([$draft, $scheduled] as $hidden) {
            // 404, not 403: a draft's existence is not public information.
            $this->get(route('journal.show', $hidden))->assertNotFound();
        }

        $index = $this->get('/journal')->assertOk()->getContent();

        $this->assertStringNotContainsString('Not finished', $index);
        $this->assertStringNotContainsString('Next week', $index);
    }

    public function test_an_empty_category_is_not_offered_as_a_filter(): void
    {
        $withWork = $this->category('Process', 'process');
        $empty = $this->category('Equipment', 'equipment');

        Article::factory()->create(['article_category_id' => $withWork->id]);

        // Published in it, but not visibly — so the category is still empty.
        Article::factory()->draft()->create(['article_category_id' => $empty->id]);

        $index = $this->get('/journal')->assertOk()->getContent();

        $this->assertStringContainsString(route('journal.category', $withWork), $index);
        $this->assertStringNotContainsString(route('journal.category', $empty), $index);

        // The page itself still exists and says so plainly.
        $this->get(route('journal.category', $empty))
            ->assertOk()
            ->assertSee('Nothing written under Equipment yet.');
    }

    // ----------------------------------------------------------------- 8

    /**
     * THE ROUTE-ORDERING TRAP.
     *
     * /journal/{article:slug} is a wildcard and would happily match the
     * literal segment "category". The work index has the same shape, and this
     * is the test that proves the declaration order is right — including in
     * the worst case, where an article genuinely has the slug "category".
     */
    public function test_a_category_slug_is_never_resolved_as_an_article(): void
    {
        $category = $this->category('Process', 'process');

        Article::factory()->create(['article_category_id' => $category->id, 'title' => 'A piece']);

        $response = $this->get('/journal/category/process');

        $response->assertOk();

        // It really is the landing page, not an article that happens to render.
        $html = $response->getContent();
        $this->assertStringContainsString('journal__title', $html);
        $this->assertStringNotContainsString('piece__body', $html);

        /*
         * And the nastiest version: an article whose slug is literally
         * "category". The literal route wins, so /journal/category is not
         * this article — and the article is still reachable at its own
         * address only through the category prefix, which does not exist.
         */
        $trap = Article::factory()->create(['title' => 'Trap']);
        $trap->slug = 'category';
        $trap->save();

        /*
         * /journal/category must be a 404 even though this article's slug is
         * literally "category".
         *
         * Declaration order alone does NOT achieve that — the literal route
         * needs a slug after it, so this URL falls through to the wildcard,
         * which happily resolved the article. This test caught exactly that
         * and the wildcard now carries a constraint. See routes/web.php.
         */
        $this->get('/journal/category')->assertNotFound();

        // The category's own pages are untouched by the constraint.
        $this->get(route('journal.category', $category))->assertOk();
    }

    // ----------------------------------------------------------------- 9

    /**
     * ARTICLE BODY HTML IS SANITISED, AND THE PROOF IS THE OUTPUT.
     *
     * Not "the sanitiser was called" — what actually reaches the page.
     */
    public function test_a_script_tag_in_the_body_never_reaches_output(): void
    {
        $payloads = [
            '<script>alert(1)</script>',
            '<script src="https://evil.test/x.js"></script>',
            '<img src=x onerror="alert(1)">',
            '<iframe src="https://evil.test"></iframe>',
            '<div onclick="steal()">text</div>',
            '[a link](javascript:alert(1))',
            '<a href="data:text/html;base64,PHNjcmlwdD4=">data</a>',
            '<style>body{display:none}</style>',
        ];

        $article = Article::factory()->create([
            'title' => 'Sanitised',
            'body' => "A real opening paragraph that survives.\n\n".implode("\n\n", $payloads)."\n\nA real closing paragraph.",
        ]);

        // --- what was stored -------------------------------------------------
        $stored = (string) $article->fresh()->body_html;

        foreach (['<script', '<iframe', '<style', 'onerror', 'onclick', 'javascript:', 'data:text/html'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored, "{$forbidden} survived into body_html");
        }

        // --- and what is served ----------------------------------------------
        $html = $this->get(route('journal.show', $article))->assertOk()->getContent();

        foreach (['alert(1)', 'evil.test', 'steal()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "{$forbidden} reached the page");
        }

        // The real prose is still there, so this is sanitisation rather than
        // the body simply being dropped.
        $this->assertStringContainsString('A real opening paragraph that survives.', $html);
        $this->assertStringContainsString('A real closing paragraph.', $html);
    }

    /**
     * The column is guarded, so nothing can put markup there without going
     * through the sanitiser — not even a deliberate mass assignment.
     */
    public function test_body_html_cannot_be_mass_assigned(): void
    {
        $article = Article::factory()->create([
            'body' => 'Honest prose.',
            'body_html' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script', (string) $article->fresh()->body_html);
        $this->assertStringContainsString('Honest prose.', (string) $article->fresh()->body_html);
    }

    // ---------------------------------------------------------------- 10

    public function test_blog_posting_structured_data_matches_the_page(): void
    {
        $author = User::factory()->create(['name' => 'A. Marsden']);
        $category = $this->category('Process', 'process');
        $media = $this->world->media('landscape');

        $article = Article::factory()->create([
            'title' => 'The edit is the work',
            'excerpt' => 'Four hundred frames, twenty-two pictures.',
            'article_category_id' => $category->id,
            'author_id' => $author->id,
            'featured_media_id' => $media->id,
        ]);

        $article->tags()->sync([Tag::named('editing')->id, Tag::named('process')->id]);

        $html = $this->get(route('journal.show', $article))->assertOk()->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        $this->assertNotEmpty($matches, 'there is no structured data on the page');

        $data = json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('BlogPosting', $data['@type']);

        // --- every claim checked against the page itself ----------------------
        $this->assertSame('The edit is the work', $data['headline']);
        $this->assertStringContainsString(e($data['headline']), $html);

        $this->assertSame(route('journal.show', $article), $data['url']);
        $this->assertSame('A. Marsden', $data['author']['name']);
        $this->assertStringContainsString('A. Marsden', $html);

        $this->assertSame('Process', $data['articleSection']);
        $this->assertSame('Four hundred frames, twenty-two pictures.', $data['description']);

        $this->assertSame(
            $article->published_at->toIso8601String(),
            $data['datePublished']
        );
        $this->assertStringContainsString($article->published_at->format('j F Y'), $html);

        $this->assertSame('editing, process', $data['keywords']);

        // Absolute, because a consumer has no page to resolve a path against.
        $this->assertStringStartsWith('http', $data['image'][0]);

        // Counted, not estimated.
        $this->assertGreaterThan(0, $data['wordCount']);
        $this->assertSame(
            str_word_count(\App\Support\RichText::toText($article->body_html)),
            $data['wordCount']
        );
    }

    /**
     * Nothing is invented. An article with no author, no category, no tags
     * and no image omits those keys rather than filling them in.
     */
    public function test_structured_data_omits_what_the_record_does_not_have(): void
    {
        Settings::set('attribution.name', '', 'string', 'attribution');
        Settings::flush();

        $article = Article::factory()->create([
            'article_category_id' => null,
            'author_id' => null,
            'featured_media_id' => null,
        ]);

        $html = $this->get(route('journal.show', $article))->assertOk()->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        $data = json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('author', $data);
        $this->assertArrayNotHasKey('articleSection', $data);
        $this->assertArrayNotHasKey('keywords', $data);
        $this->assertArrayNotHasKey('image', $data);
    }

    /**
     * A title carrying "</script>" cannot break out of the JSON-LD block.
     */
    public function test_structured_data_cannot_be_broken_out_of(): void
    {
        $article = Article::factory()->create([
            'title' => 'Closing </script><script>alert(1)</script> tags',
        ]);

        $html = $this->get(route('journal.show', $article))->assertOk()->getContent();

        /*
         * Exactly ONE script element on the page, and it is the data block.
         *
         * The literal characters "alert(1)" DO appear — escaped, as the text
         * of a heading, which is what Blade escaping is for and is entirely
         * harmless. Asserting their absence was the wrong test: it would have
         * been satisfied by dropping the title. What matters is that no second
         * <script> was opened and that the JSON did not break.
         */
        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringNotContainsString('</script><script>', $html);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        // Still valid JSON, with the title intact inside it, and the closing
        // tag escaped rather than emitted.
        $this->assertStringNotContainsString('</script>', $matches[1]);

        $data = json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('alert(1)', $data['headline']);

        // And in the visible document it is text, not markup.
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ---------------------------------------------------------------- 11

    /**
     * Query counts, pinned.
     *
     * ONE listener, registered once, outside the loop. Registered per
     * iteration it stacks and multiplies every count — the trap that has
     * faked an N+1 twice in this project already.
     */
    public function test_the_query_counts_are_flat(): void
    {
        $category = $this->category('Process', 'process');

        // Enough rows that an N+1 would be obvious rather than marginal.
        Article::factory()->count(9)->create([
            'article_category_id' => $category->id,
            'featured_media_id' => $this->world->media('landscape')->id,
        ]);

        $article = Article::query()->published()->first();
        $article->tags()->sync([Tag::named('printing')->id]);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $measure = function (string $url) use (&$count): int {
            // Settings are cached after the first read; measure the warm
            // state every page after the first sees.
            $this->get($url);

            $count = 0;
            $this->get($url)->assertOk();

            return $count;
        };

        $index = $measure('/journal');
        $show = $measure(route('journal.show', $article));
        $landing = $measure(route('journal.category', $category));

        /*
         * PINNED. Raise these deliberately, never to make a failing test pass.
         *
         *   index    — count, articles, featured media, variants, their
         *               categories, and the categories that have work in them
         *   article  — the article, its media, variants, category, tags.
         *               Five, not six: these have no author_id, and Eloquent
         *               does not query a belongsTo whose key is null.
         *   category — the category itself, then the index's six on top
         */
        $this->assertSame(6, $index, "the journal index issued {$index} queries");
        $this->assertSame(5, $show, "an article issued {$show} queries");
        $this->assertSame(7, $landing, "a category page issued {$landing} queries");

        /*
         * Flat, not merely small: the counts must not move with the number of
         * rows. Ten articles instead of nine is the same page in the same
         * number of queries, which is the property an N+1 breaks.
         */
        Article::factory()->count(10)->create([
            'article_category_id' => $category->id,
            'featured_media_id' => $this->world->media('portrait')->id,
        ]);

        $this->assertSame($index, $measure('/journal'), 'the index grew a query per row');
        $this->assertSame($landing, $measure(route('journal.category', $category)), 'the category grew a query per row');
    }

    // ---------------------------------------------------------------- 12

    /**
     * NO JAVASCRIPT ON ANY PUBLIC JOURNAL PAGE.
     *
     * One deliberate exception, stated rather than hidden: the BlogPosting
     * block is a <script type="application/ld+json">, which is how JSON-LD is
     * embedded and is the only way to satisfy "carries BlogPosting structured
     * data". It is a data block — the HTML spec classes it as a data block
     * script, browsers never execute it, and a CSP with no script-src does not
     * change its behaviour. So the assertion is the honest one: nothing
     * executes, and no script is ever fetched.
     */
    public function test_no_public_journal_page_ships_executable_javascript(): void
    {
        $category = $this->category('Process', 'process');

        $article = Article::factory()->create([
            'article_category_id' => $category->id,
            'featured_media_id' => $this->world->media('landscape')->id,
        ]);

        $pages = [
            '/journal',
            route('journal.category', $category),
            route('journal.show', $article),
            '/',   // the home page, which now carries the journal strip
        ];

        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('/<script\b([^>]*)>/i', $html, $matches);

            foreach ($matches[1] as $attributes) {
                $this->assertStringContainsString(
                    'type="application/ld+json"',
                    $attributes,
                    "{$url} has an executable <script{$attributes}>"
                );
            }

            // Nothing is fetched, by any route.
            $this->assertStringNotContainsString('<script src', $html, "{$url} loads a script file");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "{$url} has an inline event handler");

            // And Vite's JS entry is not referenced.
            $this->assertStringNotContainsString('/build/assets/admin-', $html);
        }
    }

    /**
     * The nav no longer advertises a page that goes nowhere — the defect that
     * made every page look like the current page for six tasks.
     */
    public function test_the_journal_is_a_real_link_in_the_navigation(): void
    {
        Article::factory()->create();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('journal.index').'"', $html);

        // No routeless placeholder is left in the primary navigation.
        $nav = substr($html, (int) strpos($html, '<nav class="site-nav"'));
        $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));

        $this->assertStringNotContainsString('aria-disabled', $nav);
    }

    /** The home page's preview section links through. */
    public function test_the_home_page_previews_the_journal(): void
    {
        $recent = Article::factory()->create([
            'title' => 'Three rolls in November',
            'published_at' => now()->subDay(),
        ]);

        Article::factory()->count(4)->create(['published_at' => now()->subMonths(6)]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Three rolls in November', $html);
        $this->assertStringContainsString(route('journal.show', $recent), $html);
        $this->assertStringContainsString(e((string) Settings::get('journal.home_heading')), $html);

        // Three, not five.
        $this->assertSame(3, substr_count($html, 'journal-strip__item'));
    }
}
