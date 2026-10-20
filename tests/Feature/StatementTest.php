<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Task 38 Part B — the scrubbed statement, server side.
 *
 * What a browser does with it is asserted by tests/Support/statement-check.mjs.
 * What is asserted here is what the SERVER sends: a paragraph that reads with
 * no script at all, at most one per page, and an accent rule the owner holds.
 */
class StatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
    }

    /**
     * B6 — at most one per page, on every public page, in both languages.
     *
     * Discovered from the router rather than listed, so a page added next
     * month is covered without anybody remembering it exists.
     */
    public function test_no_public_page_carries_more_than_one_scrubbed_statement(): void
    {
        $checked = 0;
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                continue;
            }

            $uri = '/'.trim($route->uri(), '/');
            $response = $this->get($uri);

            if ($response->getStatusCode() !== 200
                || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                continue;
            }

            $checked++;
            $count = preg_match_all('/data-motion="[^"]*\bstatement\b[^"]*"/', (string) $response->getContent());

            if ($count > 1) {
                $offenders[] = "{$uri} ({$count})";
            }
        }

        $this->assertGreaterThan(5, $checked, 'almost no public page answered, so this test is not looking at the site');
        $this->assertSame([], $offenders, "pages with more than one scrubbed statement:\n  ".implode("\n  ", $offenders));

        // And it is not vacuous: the home page in each language has exactly one.
        foreach (['/', '/ar'] as $home) {
            $this->assertSame(1, preg_match_all('/data-motion="[^"]*\bstatement\b/', (string) $this->get($home)->getContent()), $home);
        }
    }

    /** B6 — the mechanism: a second statement in one request simply reads. */
    public function test_a_second_statement_in_the_same_request_is_a_plain_paragraph(): void
    {
        $html = Blade::render('<x-statement>First sentence.</x-statement><x-statement>Second sentence.</x-statement>');

        $this->assertSame(1, substr_count($html, 'data-motion="statement"'));
        $this->assertMatchesRegularExpression('#<p class="statement" data-motion="statement"[^>]*>First sentence\.</p>#', $html);
        $this->assertMatchesRegularExpression('#<p class="statement">Second sentence\.</p>#', $html);
    }

    /**
     * B2 — the server's paragraph is the readable one. No word spans, no
     * inline opacity, no class that a stylesheet could dim it by: every
     * change the effect makes, the script makes, to a paragraph that already
     * reads.
     */
    public function test_the_server_sends_a_plain_readable_paragraph(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<p class="statement intro__statement" data-motion="statement"[^>]*>[^<]+</p>#', $html);
        $this->assertStringNotContainsString('statement__word', $html);
        $this->assertStringNotContainsString('statement--scrubbed', $html);
        $this->assertDoesNotMatchRegularExpression('#<p class="statement[^>]*style=#', $html);

        $css = implode("\n", array_map('file_get_contents', glob(resource_path('css/components/statement.css'))));
        $this->assertDoesNotMatchRegularExpression('/\.statement\s*\{[^}]*opacity/s', $css, 'the paragraph itself must never be dimmed by CSS');
    }

    /** B4 — the accent rule is the owner's, per locale: "last" or "none". */
    public function test_the_accent_rule_is_a_setting(): void
    {
        $this->assertStringContainsString('data-accent="last"', (string) $this->get('/')->getContent());

        Settings::set('home.statement_accent', 'none', 'string', 'home');
        Settings::flush();

        $html = (string) $this->get('/')->getContent();
        $this->assertStringNotContainsString('data-accent', $html);
        $this->assertStringContainsString('data-motion="statement"', $html, 'turning the accent off must not turn the statement off');
    }
}
