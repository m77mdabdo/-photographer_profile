<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
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
        $this->seed(SettingsSeeder::class);
        Settings::flush();
    }

    /**
     * Task 41 5.1 — AT MOST ONE PER SECTION, AND NEVER IN TWO SECTIONS IN A ROW.
     *
     * This replaces Task 38's "at most one per page", which was too strict:
     * a long page in the reference carries one per major section. Asserted on
     * the rendered DOM of every public page and of a story with two
     * quotations in a row, because the rule lives in where templates place a
     * statement and only the rendered page shows whether they kept it.
     *
     * A SECTION is a top-level block of the page: a child of <main>, of a
     * register band, or of a project's story.
     */
    public function test_no_page_puts_two_statements_in_a_section_or_in_adjacent_sections(): void
    {
        Storage::fake('media_originals');
        Storage::fake('media_public', ['url' => 'http://localhost/storage/media', 'visibility' => 'public']);
        (new DemoWorld)->story('two-quotes', [
            ['type' => 'full_width_image', 'n' => 1],
            ['type' => 'quote', 'n' => 0, 'data' => ['quote' => 'The first line worth pulling out.']],
            ['type' => 'quote', 'n' => 0, 'data' => ['quote' => 'A second, straight after it.']],
            ['type' => 'full_width_text', 'n' => 0, 'data' => ['heading' => 'Between', 'body' => 'Some words.']],
            ['type' => 'quote', 'n' => 0, 'data' => ['quote' => 'A third, a section later.']],
        ], ['editorial']);

        $urls = ['/work/two-quotes', '/ar/work/two-quotes'];
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{')) {
                $urls[] = '/'.trim($route->uri(), '/');
            }
        }

        $checked = 0;
        $offenders = [];
        $total = 0;

        foreach (array_unique($urls) as $uri) {
            $response = $this->get($uri);
            if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                continue;
            }
            $checked++;

            [$sections, $counts] = $this->statementSections((string) $response->getContent());
            $total += array_sum($counts);

            foreach ($counts as $i => $n) {
                if ($n > 1) {
                    $offenders[] = "{$uri}: {$n} statements in section ".($i + 1);
                }
                if ($n > 0 && ($counts[$i + 1] ?? 0) > 0) {
                    $offenders[] = "{$uri}: statements in sections ".($i + 1).' and '.($i + 2).', side by side';
                }
            }
        }

        $this->assertGreaterThan(5, $checked, 'almost no public page answered, so this test is not looking at the site');
        $this->assertSame([], $offenders, "statement placement broken:\n  ".implode("\n  ", $offenders));

        // Not vacuous: the pages Task 41 placed them on carry them.
        $this->assertSame(2, $this->countIn('/'), 'home carries the introduction and the closing line');
        $this->assertSame(2, $this->countIn('/work/two-quotes'), 'of two quotes in a row, only the first is a statement');
        $this->assertGreaterThanOrEqual(1, $this->countIn('/services'));
    }

    private function countIn(string $uri): int
    {
        return preg_match_all('/data-arrive="[^"]*\bstatement\b/', (string) $this->get($uri)->getContent());
    }

    /** @return array{0: list<\DOMElement>, 1: list<int>} */
    private function statementSections(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xp = new \DOMXPath($doc);

        $isSection = static function (\DOMNode $n): bool {
            $parent = $n->parentNode;
            if (! $parent instanceof \DOMElement) {
                return false;
            }
            $class = ' '.$parent->getAttribute('class').' ';

            return $parent->getAttribute('id') === 'main'
                || str_contains($class, ' register ')
                || str_contains($class, ' project__story ');
        };

        // Every section, in document order — not only the ones holding a
        // statement, or "side by side" could not be seen.
        $sections = [];
        foreach ($xp->query('//*[@id="main"]//*') as $el) {
            if ($el instanceof \DOMElement && $isSection($el) && ! str_contains(' '.$el->getAttribute('class').' ', ' register ')) {
                $sections[] = $el;
            }
        }

        $counts = array_fill(0, count($sections), 0);
        foreach ($xp->query('//*[contains(concat(" ", @data-arrive, " "), " statement ")]') as $statement) {
            for ($n = $statement; $n !== null; $n = $n->parentNode) {
                $i = array_search($n, $sections, true);
                if ($i !== false) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        return [$sections, $counts];
    }

    /** Task 41 5.1 — the per-request guard is gone: a second statement is a statement. */
    public function test_a_second_statement_in_the_same_request_is_still_a_statement(): void
    {
        $html = Blade::render('<x-statement>First sentence.</x-statement><x-statement tag="h2">Second sentence.</x-statement>');

        $this->assertSame(2, substr_count($html, 'data-arrive="statement"'));
        $this->assertMatchesRegularExpression('#<h2 class="statement" data-arrive="statement"[^>]*>Second sentence\.</h2>#', $html);
        $this->assertStringContainsString('<p class="statement"', Blade::render('<x-statement tag="script">x</x-statement>'), 'only reading elements are accepted as a tag');
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

        $this->assertMatchesRegularExpression('#<p class="statement intro__statement" data-arrive="statement"[^>]*>[^<]+</p>#', $html);
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
        $this->assertStringContainsString('data-arrive="statement"', $html, 'turning the accent off must not turn the statement off');
    }
}
