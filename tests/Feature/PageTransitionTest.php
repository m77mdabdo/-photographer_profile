<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * Task 38 Part F — which pages take part in the page transition, and what
 * the destination supplies to it. The transition itself is asserted in a
 * browser by tests/Support/transition-check.mjs.
 */
class PageTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', ['url' => 'http://localhost/storage/media', 'visibility' => 'public']);

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();

        (new DemoWorld())->story('salt-line', DemoWorld::simpleScore(3), ['landscape'], year: 2025);
    }

    private function optsIn(string $url): bool
    {
        $html = (string) $this->get($url)->assertOk()->getContent();

        return str_contains($html, '<style>'.\App\Support\PageTransition::RULE.'</style>')
            && str_contains($html, ' data-page-transition>'.\App\Support\PageTransition::SCRIPT.'</script>');
    }

    /** Both documents must opt in: the pages a project is reached from, and the project. */
    public function test_only_the_pages_around_a_project_opt_in(): void
    {
        foreach (['/', '/work', '/work/salt-line', '/ar', '/ar/work', '/ar/work/salt-line'] as $url) {
            $this->assertTrue($this->optsIn($url), "{$url} should take part in the transition");
        }

        // The archive gets no motion by decision; the rest navigate as before.
        foreach (['/archive', '/about', '/journal', '/contact'] as $url) {
            $this->assertFalse($this->optsIn($url), "{$url} should not take part in the transition");
        }
    }

    /** F3, F4 — the destination supplies its own label, rule and lead. */
    public function test_the_project_page_names_its_own_elements(): void
    {
        $html = (string) $this->get('/work/salt-line')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<p class="project__label" style="view-transition-name: project-label">[^<]*\S[^<]*</p>#u', $html);
        $this->assertStringContainsString('<span class="project__rule" aria-hidden="true" style="view-transition-name: project-rule"></span>', $html);
        $this->assertSame(1, substr_count($html, 'view-transition-name: project-lead'), 'exactly one lead picture is named');
        $this->assertSame(1, substr_count($html, 'view-transition-name: project-label'));
    }

    /**
     * The opt-in is inline and allowed by CSP HASH, so the hash in the
     * served policy must be the hash of the exact bytes in the served page:
     * one changed character and the browser drops the rule and the
     * transition silently stops. Measured from the response, not recomputed
     * from the constant alone.
     */
    public function test_the_inline_opt_in_is_exactly_what_the_policy_allows(): void
    {
        $response = $this->get('/work')->assertOk();

        preg_match('#<style>(.*?)</style>#s', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, 'no inline opt-in on the page');

        $hash = "'sha256-".base64_encode(hash('sha256', $m[1], true))."'";
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("#style-src 'self' ".preg_quote($hash, '#').'(;|$)#', $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src-attr', $policy)[0]);
        $this->assertSame(1, substr_count((string) $response->getContent(), '<style>'), 'only the one hashed inline style');
    }
}
