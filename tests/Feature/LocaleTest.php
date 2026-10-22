<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\PageController;
use App\Models\Article;
use App\Models\Page;
use App\Models\Project;
use App\Http\Middleware\HandleRedirects;
use App\Models\Redirect;
use App\Models\Service;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsNoJavaScript;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * Locale routing, locale-aware redirects, and bilingual SEO.
 *
 * Nothing here touches content: no column is translated yet, so both locales
 * render the same English copy. That is the point of doing routing first —
 * every address, every alternate and every redirect can be proved correct
 * before a single string moves, and the migration that does move them lands on
 * infrastructure that is already known good.
 */
class LocaleTest extends TestCase
{
    use AssertsNoJavaScript;
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = new DemoWorld();
    }

    // ----------------------------------------------------------------- 1

    /** Both prefixes reach every public route. */
    public function test_both_locales_resolve_every_public_route(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);

        $article = Article::factory()->create(['title' => 'The edit']);
        $service = Service::factory()->create(['name' => 'Portraits']);
        $page = Page::factory()->create(['title' => 'Privacy', 'slug' => 'privacy']);
        $project = Project::query()->first();

        $paths = [
            '/',
            '/work',
            '/work/'.$project->slug,
            '/journal',
            '/journal/'.$article->slug,
            '/services',
            '/services/'.$service->slug,
            '/about',
            '/archive',
            '/contact',
            '/'.$page->slug,
        ];

        foreach ($paths as $path) {
            foreach (Locale::codes() as $locale) {
                $url = Locale::pathFor($locale, $path);

                $this->get($url)->assertOk("$url should resolve");
            }
        }
    }

    // ----------------------------------------------------------------- 2

    /**
     * THE BARE ROOT SERVES ENGLISH, AND NEGOTIATES ONLY FOR A VISITOR WHO
     * ALREADY HAS A PREFERENCE.
     *
     * This is the decision, asserted so it cannot drift. A crawler — no
     * cookie, `Accept-Language: en` — gets the English home page at `/` with
     * no redirect, every time. That is what makes `/` a stable canonical and
     * what stops the Arabic site from being invisible to search.
     */
    public function test_the_bare_root_serves_english_and_does_not_redirect(): void
    {
        $this->get('/')->assertOk();

        // A crawler's typical headers: still 200, still English.
        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/')
            ->assertOk();

        // No preference at all: the default, not a guess.
        $this->withHeaders(['Accept-Language' => ''])->get('/')->assertOk();
    }

    /**
     * A stored preference redirects the root — and only the root.
     *
     * withUnencryptedCookie, not withCookie: the locale cookie is excepted
     * from encryption (see bootstrap/app.php) because SetLocale runs before
     * routing and therefore ahead of EncryptCookies. The test helper encrypts
     * by default, which would simulate a cookie this application never sends.
     */
    public function test_a_stored_preference_redirects_only_the_root(): void
    {
        $this->withUnencryptedCookie(Locale::COOKIE, 'ar')->get('/')->assertRedirect('/ar');

        /*
         * Every other address states its own locale and keeps it. A visitor
         * who prefers Arabic and follows a link to an English project sees the
         * English project — because that is the address they asked for, and
         * silently rewriting it would mean no English URL could be shared with
         * an Arabic-preferring reader.
         */
        $this->withUnencryptedCookie(Locale::COOKIE, 'ar')->get('/work')->assertOk();
        $this->withUnencryptedCookie(Locale::COOKIE, 'ar')->get('/about')->assertOk();
    }

    /** Accept-Language is honoured at the root, below the cookie. */
    public function test_accept_language_is_honoured_at_the_root(): void
    {
        $this->withHeaders(['Accept-Language' => 'ar,en;q=0.5'])
            ->get('/')
            ->assertRedirect('/ar');

        // The cookie outranks the header.
        $this->withUnencryptedCookie(Locale::COOKIE, 'en')
            ->withHeaders(['Accept-Language' => 'ar'])
            ->get('/')
            ->assertOk();
    }

    /** `/en/...` is an alias and says so permanently. */
    public function test_the_en_prefix_redirects_to_the_unprefixed_address(): void
    {
        $this->get('/en')->assertRedirect('/');
        $this->get('/en/work')->assertRedirect('/work');
        $this->get('/en/about')->assertRedirect('/about');

        $this->assertSame(301, $this->get('/en/work')->getStatusCode());
    }

    // ----------------------------------------------------------------- 3

    /**
     * A LOCALE PREFIX IS NEVER SWALLOWED BY A WILDCARD.
     *
     * `/{page:slug}` is the widest route on the site and `/journal/{article}`
     * is the second widest. Both would happily take `ar` for a slug.
     */
    public function test_a_locale_prefix_is_never_swallowed_by_a_wildcard(): void
    {
        // The hostile case: real published content whose slug IS a locale code.
        Page::factory()->create(['title' => 'Arabic', 'slug' => 'ar']);
        Page::factory()->create(['title' => 'English', 'slug' => 'en']);
        Article::factory()->create(['title' => 'Arabic', 'slug' => 'ar']);

        // /ar is the Arabic home page, not the page called "ar".
        $this->get('/ar')->assertOk()->assertDontSee('Arabic</h1>', false);

        // /en is the alias, not the page called "en".
        $this->get('/en')->assertRedirect('/');

        // And the article wildcard refuses them too.
        $this->get('/journal/ar')->assertNotFound();

        /*
         * The blocklist is a constant rather than an inline regex precisely so
         * this assertion is possible: the list a future reader edits is the
         * list the route uses.
         */
        $this->assertContains('ar', PageController::RESERVED);
        $this->assertContains('en', PageController::RESERVED);

        foreach (PageController::RESERVED as $segment) {
            $this->assertMatchesRegularExpression(
                '/'.PageController::slugPattern().'/',
                'a-real-page-slug',
                'the pattern should still admit an ordinary slug'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/'.PageController::slugPattern().'/',
                $segment,
                "the flat-page wildcard must refuse the reserved segment '$segment'"
            );
        }
    }

    // ----------------------------------------------------------------- 4

    /** The switcher preserves the page, including a project and an article. */
    public function test_the_switcher_preserves_the_current_page(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);

        $project = Project::query()->first();
        $article = Article::factory()->create(['title' => 'The edit']);

        $cases = [
            '/' => '/ar',
            '/work' => '/ar/work',
            '/work/'.$project->slug => '/ar/work/'.$project->slug,
            '/journal/'.$article->slug => '/ar/journal/'.$article->slug,
            '/about' => '/ar/about',
        ];

        foreach ($cases as $from => $expected) {
            $this->post('/locale', ['locale' => 'ar', 'return_to' => $from])
                ->assertRedirect($expected);
        }

        // And back again, landing exactly where it started.
        foreach ($cases as $english => $arabic) {
            $this->post('/locale', ['locale' => 'en', 'return_to' => $arabic])
                ->assertRedirect($english);
        }
    }

    /** The switcher stores the choice, and nothing else does. */
    public function test_only_the_switcher_writes_a_preference(): void
    {
        /*
         * The third argument is `encrypted: false`. assertCookie decrypts by
         * default, and this cookie is deliberately not encrypted — see
         * bootstrap/app.php. Asserting it the default way would decrypt
         * plaintext and throw.
         */
        $this->post('/locale', ['locale' => 'ar', 'return_to' => '/work'])
            ->assertCookie(Locale::COOKIE, 'ar', false);

        // Merely visiting an Arabic page is not a choice.
        $this->get('/ar/work')->assertCookieMissing(Locale::COOKIE);
        $this->get('/')->assertCookieMissing(Locale::COOKIE);
    }

    /** A return_to pointing off-site is refused. */
    public function test_the_switcher_refuses_an_open_redirect(): void
    {
        $this->post('/locale', ['locale' => 'ar', 'return_to' => 'https://evil.example/x'])
            ->assertRedirect('/ar');

        // Protocol-relative, which a naive leading-slash check would admit.
        $this->post('/locale', ['locale' => 'ar', 'return_to' => '//evil.example/x'])
            ->assertRedirect('/ar');
    }

    // ----------------------------------------------------------------- 5

    /** dir and lang are server-rendered and correct, with no JavaScript. */
    public function test_dir_and_lang_follow_the_locale_without_javascript(): void
    {
        /*
         * ───────────────────────────────────────────────────────────────────
         * CONTENT FIRST, AND TASK 36b IS WHY.
         *
         * This test fetched / and /ar against an EMPTY database. The rule
         * below — every dir="ltr" must also declare lang="en" — then ran
         * against a page with no reversing elements on it at all, so the
         * foreach iterated zero times and asserted nothing.
         *
         * Proved by breaking it on purpose: with the fallback annotation
         * emitting dir="ltr" and no lang, the exact defect this guards
         * against, all twenty tests in this file still passed.
         *
         * The same shape as the blind spot Task 36b found in
         * ArabicRenderTest: a check whose fixture makes the thing it checks
         * impossible. So there is content now, untranslated on purpose, which
         * is what produces a reversing element — and the count is asserted
         * before the rule, because a vacuous loop must never pass again.
         * ───────────────────────────────────────────────────────────────────
         */
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);
        Article::factory()->create(['title' => 'The edit is the work']);
        Service::factory()->create(['name' => 'Editorial commissions']);

        $english = $this->get('/')->assertOk()->getContent();
        $arabic = $this->get('/ar')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en" dir="ltr"', $english);
        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $arabic);

        /*
         * The attributes are in the markup the server sent, so there is
         * nothing to reconcile after paint. A direction that settled in later
         * would relay out the whole document in front of the reader.
         *
         * ───────────────────────────────────────────────────────────────────
         * ONE ELEMENT MAY REVERSE, AND ONLY ONE KIND: A LANGUAGE'S OWN NAME.
         *
         * 34c made the three switchers one component, and with it the public
         * one gained what the panel's had: lang and dir on each option, so
         * "English" inside an Arabic page is marked as English rather than
         * left to the bidi algorithm. That is not a direction settling late —
         * it is a correctly annotated run of foreign text, and it is in the
         * first byte like everything else here.
         *
         * So the exception is BOUNDED rather than removed. Every dir="ltr"
         * inside an Arabic body must be on an element that also declares
         * lang="en"; anything else — a layout div, a card, a section — fails
         * exactly as it did before.
         * ───────────────────────────────────────────────────────────────────
         */
        $body = explode('<body', $arabic)[1] ?? '';

        preg_match_all('/<[^>]*dir="ltr"[^>]*>/', $body, $reversed);

        $this->assertNotEmpty(
            $reversed[0],
            'no element on the Arabic page reverses direction, so the rule below '
            .'asserts nothing. The fixture above exists to produce one.'
        );

        foreach ($reversed[0] as $element) {
            $this->assertMatchesRegularExpression(
                '/lang="en"/',
                $element,
                'an element reverses direction without naming a language: '.$element,
            );
        }
    }

    // ----------------------------------------------------------------- 6

    /** hreflang is present, complete, reciprocal, and carries x-default. */
    public function test_hreflang_alternates_are_present_and_reciprocal(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);

        $project = Project::query()->first();

        foreach (['/', '/work', '/work/'.$project->slug, '/journal', '/about'] as $path) {
            foreach (Locale::codes() as $locale) {
                $url = Locale::pathFor($locale, $path);
                $html = $this->get($url)->assertOk()->getContent();

                // Every locale is named, from every locale's page.
                foreach (Locale::codes() as $other) {
                    $expected = url(Locale::pathFor($other, $path));

                    $this->assertStringContainsString(
                        '<link rel="alternate" hreflang="'.Locale::html($other).'" href="'.$expected.'">',
                        $html,
                        "$url should name the ".$other.' alternate'
                    );
                }

                // x-default, pointing at the default locale's address.
                $this->assertStringContainsString(
                    '<link rel="alternate" hreflang="x-default" href="'.url(Locale::pathFor(Locale::DEFAULT, $path)).'">',
                    $html,
                    "$url should carry x-default"
                );
            }
        }
    }

    // ----------------------------------------------------------------- 7

    /** Canonical names the current locale's address and never the other's. */
    public function test_canonical_never_points_across_locales(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);

        $project = Project::query()->first();

        foreach (['/', '/work', '/work/'.$project->slug, '/about', '/services'] as $path) {
            foreach (Locale::codes() as $locale) {
                $url = Locale::pathFor($locale, $path);
                $html = $this->get($url)->assertOk()->getContent();

                preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $m);

                $this->assertNotEmpty($m, "$url should have a canonical");
                $this->assertSame(url($url), $m[1], "$url canonical should be itself");
            }
        }
    }

    // ----------------------------------------------------------------- 8

    /**
     * A MISS STILL COSTS NO QUERY, WITH LOCALES IN PLAY.
     *
     * The load-bearing property. 404s are what crawlers and scanners generate
     * in volume, and a redirect feature that queries on every one of them is a
     * load amplifier rather than a feature.
     */
    public function test_a_redirect_miss_issues_no_database_query(): void
    {
        Redirect::query()->create([
            'from_path' => '/work/old-name',
            'to_path' => '/work/new-name',
            'status_code' => 301,
            'is_active' => true,
            'source' => 'manual',
        ]);

        // Warm the map once, exactly as the first request of a process would.
        HandleRedirects::map();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        /*
         * Counted against the REDIRECTS TABLE specifically, not against every
         * query on the request. A 404 that falls through to the flat-page
         * wildcard legitimately costs one `pages` lookup — that is the router
         * resolving a binding, and it happens whether or not this middleware
         * exists. What must stay at zero is the middleware's own cost.
         */
        foreach (['/nothing-here', '/ar/nothing-here', '/ar/deeply/nested/miss', '/deeply/nested/miss'] as $miss) {
            $queries = [];

            $this->get($miss);

            $touched = array_values(array_filter(
                $queries,
                static fn (string $sql): bool => str_contains($sql, 'redirects')
            ));

            $this->assertSame([], $touched, "a miss on $miss should not touch the redirects table");
        }
    }

    // ----------------------------------------------------------------- 9

    /**
     * THE LOCALE REDIRECT RULE: an address, not a language.
     *
     * A redirect matches the full path including its prefix. One created for
     * English does not fire for Arabic, and vice versa.
     */
    public function test_a_redirect_belongs_to_the_locale_in_its_address(): void
    {
        Redirect::query()->create([
            'from_path' => '/work/english-only',
            'to_path' => '/work/salt-line',
            'status_code' => 301,
            'is_active' => true,
            'source' => 'manual',
        ]);

        Redirect::forgetMap();

        // Fires for the address it names.
        $this->get('/work/english-only')->assertRedirect('/work/salt-line');

        // And does NOT leak into the other locale.
        $this->get('/ar/work/english-only')->assertNotFound();

        // The mirror image holds too.
        Redirect::query()->create([
            'from_path' => '/ar/work/arabic-only',
            'to_path' => '/ar/work/salt-line',
            'status_code' => 301,
            'is_active' => true,
            'source' => 'manual',
        ]);

        Redirect::forgetMap();

        $this->get('/ar/work/arabic-only')->assertRedirect('/ar/work/salt-line');
        $this->get('/work/arabic-only')->assertNotFound();
    }

    /** The admin guard still holds when a locale prefix is in front of it. */
    public function test_a_redirect_cannot_reach_the_admin_under_a_locale_prefix(): void
    {
        Redirect::query()->create([
            'from_path' => '/ar/admin/projects',
            'to_path' => '/work',
            'status_code' => 301,
            'is_active' => true,
            'source' => 'manual',
        ]);

        Redirect::forgetMap();

        /*
         * The protected-prefix check used to read the first path segment,
         * which for this path is "ar" rather than "admin" — so the guard that
         * stops a redirect row intercepting an editor silently stopped
         * covering anything behind a locale prefix.
         *
         * ASSERTED AS "THE ROW DID NOT FIRE", not as a 404. /ar/admin is now a
         * real route — the door that stores a language preference and sends an
         * editor to the panel's one address — so the status is a 302. What
         * must remain true is that it goes to the ADMIN, never to the
         * redirect row's destination.
         */
        $response = $this->get('/ar/admin/projects');

        $this->assertNotSame(
            url('/work'),
            $response->headers->get('location'),
            'a redirect row must never be able to intercept an admin path'
        );

        $response->assertRedirect('/admin/projects');
    }

    // ----------------------------------------------------------------- 10

    /** Sitemaps carry alternates, and still exclude non-public content. */
    public function test_sitemaps_carry_alternates_and_still_exclude_drafts(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);

        $draft = Project::factory()->create(['title' => 'Unfinished', 'slug' => 'unfinished']);

        $xml = $this->get('/sitemap-projects.xml')->assertOk()->getContent();

        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);

        foreach (Locale::codes() as $locale) {
            $this->assertStringContainsString(
                'hreflang="'.Locale::html($locale).'"',
                $xml,
                'the sitemap should name every locale'
            );
        }

        $this->assertStringContainsString('hreflang="x-default"', $xml);

        // Both addresses of the published project are named.
        $this->assertStringContainsString(url('/work/salt-line'), $xml);
        $this->assertStringContainsString(url('/ar/work/salt-line'), $xml);

        // And the draft appears at neither.
        $this->assertStringNotContainsString('unfinished', $xml);
    }

    /** robots.txt disallows the per-locale paths in both prefixes. */
    public function test_robots_covers_both_prefixes(): void
    {
        $this->app['env'] = 'production';

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        foreach (Locale::codes() as $locale) {
            $this->assertStringContainsString(
                'Disallow: '.Locale::pathFor($locale, '/contact/sent'),
                $body
            );
        }
    }

    // ----------------------------------------------------------------- 11

    /**
     * QUERY COUNTS ARE UNCHANGED BY LOCALE.
     *
     * Routing is not a data concern. The Arabic page runs the same queries the
     * English one does, because it is the same controller reading the same
     * rows — no join, no second lookup, nothing per-locale at all until step 3
     * makes the columns translatable.
     */
    public function test_query_counts_are_unchanged_per_locale(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(6), ['landscape'], year: 2025);
        Project::query()->first()?->forceFill(['is_featured' => true])->save();
        Article::factory()->count(3)->create();
        Service::factory()->count(3)->create();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $measure = function (string $url) use (&$count): int {
            $this->get($url);   // warm the site-wide maps

            $count = 0;
            $this->get($url)->assertOk();

            return $count;
        };

        /*
         * The home page rose 11 → 12 in Task 34 D2: the testimonial strip is
         * one flat select, warm, in every locale. It is NOT cached like the
         * navigation, because a published quote can be scheduled and a cache
         * that outlives its publish date would hold it back.
         */
        // 12 -> 13: the cinematic sections, one select whatever their number (lighting brief, 22 Oct 2026).
        $expected = ['/' => 13, '/journal' => 3, '/services' => 1, '/work' => 6];

        foreach ($expected as $path => $queries) {
            foreach (Locale::codes() as $locale) {
                $url = Locale::pathFor($locale, $path);

                $this->assertSame($queries, $measure($url), "$url query count moved");
            }
        }
    }

    // ----------------------------------------------------------------- 12

    /**
     * NO NEW JAVASCRIPT, IN EITHER LOCALE.
     *
     * The site does ship JavaScript now — the arrivals and the page
     * transition — so "zero script tags" stopped being the contract. The
     * contract that survives, and the one this task must not break, is that
     * locale support added NONE of it: the switcher is a form, the direction
     * is an attribute, and an Arabic page carries exactly the same
     * permitted script forms an English one does.
     */
    public function test_locale_support_added_no_javascript(): void
    {
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);

        $project = Project::query()->first();

        foreach (['/', '/work', '/work/'.$project->slug, '/journal', '/services', '/about', '/archive', '/contact'] as $path) {
            foreach (Locale::codes() as $locale) {
                $url = Locale::pathFor($locale, $path);
                $html = $this->get($url)->assertOk()->getContent();

                $this->assertNoExecutableJavaScript($html, $url);

                // The switcher is a form, and it works without script.
                $this->assertStringContainsString('data-locale-switcher=', $html, "$url should carry the switcher");
                $this->assertStringContainsString('method="POST"', $html);
            }
        }
    }

    /** Both locales carry an identical script budget — no per-locale bundle. */
    public function test_the_two_locales_ship_the_same_scripts(): void
    {
        $english = $this->get('/')->assertOk()->getContent();
        $arabic = $this->get('/ar')->assertOk()->getContent();

        $scriptsOf = function (string $html): array {
            preg_match_all('#<script[^>]*>#', $html, $m);

            return $m[0];
        };

        $this->assertSame(
            count($scriptsOf($english)),
            count($scriptsOf($arabic)),
            'the Arabic page should ship exactly the scripts the English one does'
        );
    }
}
