<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Support\ReturnPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 C3 — "IS COMPRESSION APPLIED WHERE IT LEAKS?"
 *
 * It was. Task 35 gzipped every HTML response; every public page carries a
 * CSRF token for the theme and language forms; and several of those pages
 * echoed the caller's own query string or path back into the body. A secret
 * and attacker-chosen text, compressed together, is a BREACH oracle: the
 * compressed length varies with how much the guess overlaps the secret.
 *
 * Measured with REFLECTME as the probe. Three leaks, found in two passes
 * because fixing the first uncovered the second:
 *
 *   before anything
 *     GET /work?q=REFLECTME          1 echo    the theme form's return_to
 *     GET /no-such-page-REFLECTME    7 echoes  return_to twice, canonical,
 *                                              og:url, three hreflang
 *   after the forms were cleaned
 *     GET /work?page=2&q=REFLECTME   2 echoes  canonical and og:url, built
 *                                              from the paginator's url()
 *     GET /archive?year=REFLECTME    1 echo    the pager's rel=next link
 *     GET /no-such-page-REFLECTME    7 echoes  unchanged — the path itself
 *
 * After all three: zero echoes on every surface below, with the token still
 * present. The prize was only ever the CSRF token — session and remember-me
 * cookies travel in headers, which this application never compresses — but it
 * was a secret recoverable from a page nobody has to sign in to see.
 *
 * TWO OF THE FOUR WERE ALSO PLAIN BUGS. A canonical that echoes ?utm_source=
 * tells a crawler that every tagged variant is its own page, which is the
 * exact duplication canonical exists to collapse. And a pager link that
 * carries unknown parameters propagates them through the whole list.
 *
 * WHAT IS NOT FIXED, and is in the report rather than hidden: behind the
 * login, ReturnPath keeps the whole URI, because a panel deep link carries
 * tableFilters and a reset link carries ?email=. Those pages cannot be
 * reached unauthenticated, so the oracle has no anonymous reader.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ReflectionTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = 'REFLECTME';

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Content, because a pager that has only one page emits no links and
         * would make the pager half of this test vacuous.
         */
        $this->world = new DemoWorld;

        for ($i = 0; $i < 16; $i++) {
            $this->world->story('reflect-'.$i, DemoWorld::simpleScore(1), ['landscape']);
        }
    }

    public static function publicSurfaces(): array
    {
        return [
            'a list with an unknown parameter' => ['/work?q=REFLECTME'],
            'page two with an unknown parameter' => ['/work?page=2&q=REFLECTME'],
            'a filtered archive with junk' => ['/archive?year=2026&category=editorial&junk=REFLECTME'],
            'a filter whose value is the payload' => ['/archive?year=REFLECTME'],
            'a page number that is the payload' => ['/work?page=REFLECTME'],
            'a 404 whose path is the payload' => ['/no-such-page-REFLECTME'],
            'an Arabic 404 whose path is the payload' => ['/ar/no-such-page-REFLECTME'],
            'the journal with junk' => ['/journal?utm_source=REFLECTME'],
        ];
    }

    #[DataProvider('publicSurfaces')]
    public function test_a_public_page_echoes_nothing_the_caller_chose(string $path): void
    {
        $body = (string) $this->get($path)->getContent();

        $this->assertStringNotContainsString(
            self::PROBE,
            $body,
            "GET {$path} reflects the caller's own text into a page that also carries the CSRF token."
        );
    }

    /**
     * AND THE SECRET IS STILL THERE, which is what makes the test above mean
     * something. If the token had been removed instead, every assertion here
     * would pass for the wrong reason.
     */
    #[DataProvider('publicSurfaces')]
    public function test_those_same_pages_still_carry_the_csrf_token(string $path): void
    {
        $body = (string) $this->get($path)->getContent();

        $this->assertMatchesRegularExpression(
            '/name="_token"|name="csrf-token"/',
            $body,
            "GET {$path} no longer carries a CSRF token; this test's premise has changed."
        );
    }

    // ── THE CANONICAL, WHICH WAS ALSO AN SEO DEFECT ───────────────────────

    public function test_page_two_canonicalises_to_itself_without_the_junk(): void
    {
        $body = (string) $this->get('/work?page=2&utm_source=newsletter&q=REFLECTME')->getContent();

        preg_match('#<link rel="canonical" href="([^"]+)"#', $body, $m);

        $this->assertNotEmpty($m, 'page two has no canonical');
        $this->assertStringEndsWith('/work?page=2', $m[1]);
    }

    /**
     * The pager was the other half of it: withQueryString() put every
     * parameter on every pager link, so one junk parameter propagated through
     * the whole list. /work, because sixteen fixtures across twelve per page
     * guarantees there IS a second page — the archive's row count depends on
     * how many frames the fixture happens to produce.
     */
    public function test_a_pager_link_carries_nothing_the_caller_added(): void
    {
        $body = (string) $this->get('/work?junk=REFLECTME&utm_source=newsletter')->getContent();

        preg_match_all('#href="([^"]*page=\d+[^"]*)"#', $body, $m);

        $this->assertNotEmpty($m[1], 'the work index emitted no pager links; the fixture is too small');

        foreach ($m[1] as $href) {
            $this->assertStringNotContainsString('junk', $href);
            $this->assertStringNotContainsString('utm_source', $href);
            $this->assertStringNotContainsString(self::PROBE, $href);
        }
    }

    /** And a filter the site DOES read survives the trip, or the pager lies. */
    public function test_a_pager_link_keeps_a_filter_the_site_reads(): void
    {
        $this->assertSame(
            ['category' => 'editorial', 'year' => '2026'],
            ReturnPath::publicQuery(Request::create('/archive?category=editorial&year=2026&junk=REFLECTME&page=4')),
        );
    }

    // ── AN ERROR PAGE HAS NO ADDRESS ──────────────────────────────────────

    public static function errorPages(): array
    {
        return [
            'a 404' => ['/no-such-page', 404],
            'an Arabic 404' => ['/ar/no-such-page', 404],
        ];
    }

    #[DataProvider('errorPages')]
    public function test_an_error_page_names_no_canonical_and_no_alternates(string $path, int $status): void
    {
        $response = $this->get($path);

        $response->assertStatus($status);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('rel="canonical"', $body);
        $this->assertStringNotContainsString('hreflang', $body);
        $this->assertStringNotContainsString('og:url', $body);

        // It is still an error page, and still asks not to be indexed.
        $this->assertStringContainsString('error-page', $body);
        $this->assertStringContainsString('noindex', $body);
    }

    /** And a real page still has both, or the rule above would be vacuous. */
    public function test_a_real_page_still_names_both(): void
    {
        $body = (string) $this->get('/work')->getContent();

        $this->assertStringContainsString('rel="canonical"', $body);
        $this->assertStringContainsString('hreflang="x-default"', $body);
    }

    /** Switching language on a 404 goes home, not back to the 404. */
    public function test_the_preference_forms_on_an_error_page_return_home(): void
    {
        $body = (string) $this->get('/no-such-page-REFLECTME')->getContent();

        preg_match_all('#name="return_to" value="([^"]*)"#', $body, $m);

        $this->assertNotEmpty($m[1], 'the error page has no preference forms at all');

        foreach ($m[1] as $value) {
            $this->assertSame('/', $value);
        }
    }

    // ── THE HELPER ITSELF ─────────────────────────────────────────────────

    public static function returnPaths(): array
    {
        return [
            'nothing to keep' => ['/work', '', '/work'],
            'an unknown parameter' => ['/work', 'q=REFLECTME', '/work'],
            'a page number' => ['/work', 'page=3', '/work?page=3'],
            'page one is not a page number' => ['/work', 'page=1', '/work'],
            'a page number that is text' => ['/work', 'page=REFLECTME', '/work'],
            'a page number past the end of reason' => ['/work', 'page=99999999', '/work'],
            'a year' => ['/archive', 'year=2026', '/archive?year=2026'],
            'a year that is text' => ['/archive', 'year=REFLECTME', '/archive'],
            'a year outside photography' => ['/archive', 'year=1066', '/archive'],
            'a category slug' => ['/archive', 'category=editorial', '/archive?category=editorial'],
            'a category that is not a slug' => ['/archive', 'category=<script>', '/archive'],
            'both filters and junk' => ['/archive', 'category=editorial&year=2026&junk=REFLECTME', '/archive?category=editorial&year=2026'],
        ];
    }

    #[DataProvider('returnPaths')]
    public function test_the_return_path_keeps_only_what_the_site_reads(string $path, string $query, string $expected): void
    {
        $request = Request::create($path.($query === '' ? '' : '?'.$query));

        $this->assertSame($expected, ReturnPath::current($request));
    }

    /**
     * BEHIND THE LOGIN, EVERYTHING. A panel deep link carries tableFilters
     * and a password-reset address carries ?email=; losing either sends
     * somebody back to the start of something.
     */
    public static function privatePaths(): array
    {
        return [
            'the panel with filters' => ['/admin/projects?tableFilters[status][value]=draft'],
            'a reset link' => ['/reset-password/token?email=someone%40example.test'],
            'the panel root' => ['/admin'],
        ];
    }

    #[DataProvider('privatePaths')]
    public function test_a_private_path_keeps_its_whole_query(string $uri): void
    {
        $request = Request::create($uri);

        $this->assertSame($request->getRequestUri(), ReturnPath::current($request));
    }

    /** The paginator's own parameter is the paginator's to write. */
    public function test_the_paginator_is_not_handed_back_its_own_page_parameter(): void
    {
        $request = Request::create('/archive?page=3&year=2026');

        $this->assertSame(['year' => '2026'], ReturnPath::publicQuery($request));
    }
}
