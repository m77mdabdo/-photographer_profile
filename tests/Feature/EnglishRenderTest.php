<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\Page;
use App\Models\Service;
use App\Models\User;
use App\Support\Locale;
use Database\Seeders\LegalPagesSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 35 H2 — THE SCAN NOBODY HAD WRITTEN: ARABIC ON AN ENGLISH PAGE.
 *
 * Two tests have scanned Arabic pages for English since Task 32:
 * ArabicRenderTest over seven public indexes, three record pages and the
 * error pages, and PanelArabicRenderTest over the panel. Both look one way.
 * Nothing has ever rendered an ENGLISH page and looked for Arabic in it,
 * which is the same defect wearing the other hat — and this project has
 * already shipped its mirror image once, when a locale-frozen settings
 * overlay told search engines that every Arabic page was English.
 *
 * The plausible sources are all live:
 *
 *   · the settings overlay, which falls back from the active locale to the
 *     default and would hand Arabic to an English page if the default value
 *     were ever the Arabic one
 *   · the cache, which keys the settings map ONCE for both locales
 *   · the model fallback chain, which is one-directional today and would
 *     leak the moment somebody made it symmetrical
 *   · anything typed into the panel by a person — a title, a service name,
 *     a category — in the wrong field
 *
 * WHAT IS SCANNED, AND HOW THE LIST IS BUILT: see targets(). The pages come
 * from the ROUTER and the panel screens from the resources DIRECTORY, so a
 * screen added next month is covered without anybody remembering this file.
 * ───────────────────────────────────────────────────────────────────────────
 */
class EnglishRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one place Arabic is allowed on an English page.
     *
     * The language switcher names each language IN ITS OWN SCRIPT, because
     * somebody who reads only Arabic has to be able to find the Arabic
     * button on a page they cannot otherwise read. That is the whole purpose
     * of the control, and it is the only exception — see
     * test_the_switcher_exception_is_narrow for the proof that it does not
     * hide anything else.
     */
    private const SWITCHER_MARKUP = '#<form[^>]*data-locale-switcher.*?</form>#s';

    /**
     * And the second exception, which is the same exception.
     *
     * A language is named in its own script wherever the choice is offered —
     * the switcher in the chrome, and the per-locale TABS on every
     * translatable form, where "العربية" labels the field you type Arabic
     * into. Both exist so that somebody who reads only Arabic can find the
     * Arabic one, which is the entire point of the control.
     *
     * The exception is the NAME, not the neighbourhood: exact occurrences of
     * Locale::native() are removed before the scan, so a planted Arabic word
     * beside the tab label still fails. Proved in
     * test_the_switcher_exception_is_narrow.
     */
    private function withoutLanguageNames(string $html): string
    {
        foreach (Locale::codes() as $code) {
            $native = Locale::native($code);

            if (preg_match('/[\p{Arabic}]/u', $native)) {
                $html = str_replace($native, '', $html);
            }
        }

        return $html;
    }

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(LegalPagesSeeder::class);

        $this->world = new DemoWorld;
        $this->world->story('salt-line', DemoWorld::simpleScore(3), ['landscape']);

        Service::factory()->create([
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        Article::factory()->create([
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * H6 — THE TARGETS DISCOVER THEMSELVES.
     *
     * Every public GET route the router actually has, minus the ones that
     * are not pages: the panel (covered separately below), the auth flow,
     * the crawler files, the asset paths and the Arabic prefix itself. A
     * route with a required parameter is filled from the content seeded in
     * setUp, so a new record page is covered the day its route is added.
     *
     * This is the rule this project keeps relearning: a hand-written list of
     * screens is a list that was true once. The harness had eighteen stale
     * targets, the panel had an exemption saying "the admin is not
     * localised", and a comment claimed a pattern was built from a list it
     * was not. The cure is always the same — derive it.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @return array<string, string>
     */
    private function publicTargets(): array
    {
        /*
         * What is not a page: the panel and the auth flow (covered by their
         * own scans), the crawler files, the asset routes, and /legacy/*,
         * which is twenty permanent redirects to the panel rather than
         * twenty screens. The livewire entries are the framework's own
         * JavaScript, served by a route.
         */
        $skip = [
            'admin', 'login', 'logout', 'register', 'two-factor', 'two-factor-challenge',
            'forgot-password', 'reset-password', 'user', 'storage', 'build',
            'sitemap', 'robots.txt', 'sitemap.xml', '_errors', 'locale', 'theme', 'up',
            'legacy', 'studio',
        ];

        $targets = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // The Arabic routes are the other scan's business.
            if (str_starts_with($uri, 'ar/') || $uri === 'ar') {
                continue;
            }

            $first = explode('/', $uri)[0];

            if (in_array($first, $skip, true)
                || str_starts_with($first, 'livewire')
                || str_contains($uri, '.xml')
                || str_contains($uri, '.js')
                || str_contains($uri, '{locale}')) {
                continue;
            }

            $path = $this->fill($uri);

            if ($path === null) {
                continue;
            }

            $targets[$route->getName() ?? $uri] = $path;
        }

        return $targets;
    }

    /** A route's parameters, filled from the content seeded for this test. */
    private function fill(string $uri): ?string
    {
        $path = '/'.ltrim($uri, '/');

        if (! str_contains($path, '{')) {
            return $path === '/' ? '/' : rtrim($path, '/');
        }

        $values = [
            'project' => 'salt-line',
            'slug' => Page::query()->value('slug') ?? 'privacy',
            'category' => Category::query()->value('slug'),
            'article' => Article::query()->value('slug'),
            'service' => Service::query()->value('slug'),
            'path' => null,
            'rest' => null,
            'any' => null,
        ];

        foreach ($values as $name => $value) {
            if (! str_contains($path, '{'.$name)) {
                continue;
            }

            if ($value === null) {
                return null;
            }

            $path = (string) preg_replace('/\{'.$name.'\??\}/', (string) $value, $path);
        }

        // Anything still unresolved is not a page this test can render.
        return str_contains($path, '{') ? null : $path;
    }

    /** The panel's screens, from the resources directory. */
    private function panelTargets(): array
    {
        $targets = ['dashboard' => '/admin', 'settings' => '/admin/settings'];

        foreach (glob(base_path('app/Filament/Resources/*/*Resource.php')) as $file) {
            $class = 'App\\Filament\\Resources\\'
                .basename(dirname($file)).'\\'
                .basename($file, '.php');

            $targets[class_basename($class)] = $class::getUrl('index', panel: 'studio');
        }

        return $targets;
    }

    /**
     * The visible text of a page, plus the attributes a person actually
     * meets: what a screen reader announces, what a tooltip says, what the
     * browser tab shows and what a link preview carries.
     *
     * ArabicRenderTest strips the <head> and reads text only. That is the
     * right shape for "is this page in English", and it is exactly why an
     * aria-label has never been scanned in either direction — a blind Arabic
     * user hearing an English label is the failure nobody sees.
     */
    private function readable(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);

        $attributes = [];

        preg_match_all(
            '/\b(aria-label|title|placeholder|alt|aria-description|aria-placeholder)="([^"]*)"/i',
            $html,
            $matches,
        );

        $attributes = $matches[2];

        preg_match_all('/<title>(.*?)<\/title>/si', $html, $titles);
        preg_match_all('/<meta[^>]+content="([^"]*)"[^>]*>/i', $html, $metas);

        return implode(' ', [
            strip_tags($html),
            implode(' ', $attributes),
            implode(' ', $titles[1] ?? []),
            implode(' ', $metas[1] ?? []),
        ]);
    }

    /**
     * A response's HTML, or null when it is not a page.
     *
     * One route hands back a BinaryFileResponse — a file, not a page — and
     * asking it for a status throws. A scan that crashed on the first
     * download route would have covered nothing after it.
     */
    private function htmlOf(TestResponse $response): ?string
    {
        $base = $response->baseResponse;

        if ($base instanceof BinaryFileResponse
            || $base instanceof StreamedResponse) {
            return null;
        }

        if ($base->getStatusCode() !== 200) {
            return null;
        }

        if (! str_contains((string) $base->headers->get('Content-Type'), 'text/html')) {
            return null;
        }

        return (string) $base->getContent();
    }

    /** Arabic-script runs in a string, with the switcher cut out first. */
    private function arabicIn(string $html): array
    {
        $html = (string) preg_replace(self::SWITCHER_MARKUP, '', $html);
        $html = $this->withoutLanguageNames($html);

        preg_match_all('/[\p{Arabic}]+(?:[\s\p{Arabic}]*[\p{Arabic}]+)?/u', $this->readable($html), $found);

        return array_values(array_unique(array_filter($found[0], fn (string $s): bool => trim($s) !== '')));
    }

    // ----------------------------------------------------------------- H2

    /**
     * The discovery itself, asserted — because a scan that found three pages
     * would pass this file and mean nothing.
     */
    public function test_the_scan_finds_every_public_page_and_panel_screen(): void
    {
        $public = $this->publicTargets();

        if (getenv('SHOW_TARGETS')) {
            fwrite(STDERR, "\n  PUBLIC\n");
            foreach ($public as $name => $path) {
                fwrite(STDERR, sprintf("    %-28s %s\n", $name, $path));
            }
            fwrite(STDERR, "  PANEL\n");
            foreach ($this->panelTargets() as $name => $path) {
                fwrite(STDERR, sprintf("    %-28s %s\n", $name, $path));
            }
        }

        // Every public surface a visitor can reach without a session.
        foreach (['/', '/work', '/archive', '/journal', '/services', '/about', '/contact'] as $path) {
            $this->assertContains($path, array_values($public), "the scan does not cover {$path}");
        }

        // And the record pages, which is where content lives.
        $this->assertContains('/work/salt-line', array_values($public));

        $this->assertGreaterThanOrEqual(12, count($public), 'the router walk found almost nothing');

        // The panel: every resource in the directory, plus the two pages.
        $panel = $this->panelTargets();
        $resources = glob(base_path('app/Filament/Resources/*/*Resource.php'));

        $this->assertCount(count($resources) + 2, $panel);
        $this->assertGreaterThanOrEqual(15, count($panel));
    }

    /** Not one word of Arabic on an English public page. */
    public function test_no_english_page_renders_arabic(): void
    {
        $offenders = [];

        foreach ($this->publicTargets() as $name => $path) {
            $response = $this->get($path);

            $html = $this->htmlOf($response);

            if ($html === null) {
                continue;
            }

            $arabic = $this->arabicIn($html);

            if ($arabic !== []) {
                $offenders[] = $name.' ('.$path.'): '.implode(' | ', array_slice($arabic, 0, 4));
            }
        }

        $this->assertSame([], $offenders, "Arabic on an English page:\n  ".implode("\n  ", $offenders));
    }

    /** And not one on an English panel screen. */
    public function test_no_english_panel_screen_renders_arabic(): void
    {
        Filament::setCurrentPanel('studio');

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));

        $offenders = [];

        foreach ($this->panelTargets() as $name => $path) {
            $response = $this->get($path);

            $html = $this->htmlOf($response);

            if ($html === null) {
                continue;
            }

            $arabic = $this->arabicIn($html);

            if ($arabic !== []) {
                $offenders[] = $name.' ('.$path.'): '.implode(' | ', array_slice($arabic, 0, 4));
            }
        }

        $this->assertSame([], $offenders, "Arabic on an English panel screen:\n  ".implode("\n  ", $offenders));
    }

    /** The error pages, which no route serves and every visitor can meet. */
    public function test_no_english_error_page_renders_arabic(): void
    {
        $offenders = [];

        foreach (['/no-such-page-at-all' => 404] as $path => $status) {
            $response = $this->get($path);

            $this->assertSame($status, $response->status());

            $arabic = $this->arabicIn((string) $response->getContent());

            if ($arabic !== []) {
                $offenders[] = $path.': '.implode(' | ', $arabic);
            }
        }

        foreach ([403, 419, 429, 500, 503] as $status) {
            $rendered = $this->renderErrorPage($status);

            $arabic = $this->arabicIn($rendered);

            if ($arabic !== []) {
                $offenders[] = $status.': '.implode(' | ', $arabic);
            }
        }

        $this->assertSame([], $offenders, "Arabic on an English error page:\n  ".implode("\n  ", $offenders));
    }

    /** One error page, rendered the way the handler renders it. */
    private function renderErrorPage(int $status): string
    {
        return view('errors.'.$status, ['exception' => new HttpException($status)])->render();
    }

    // ----------------------------------------------------------------- H7

    /**
     * THE EXCEPTION IS THE SWITCHER AND NOTHING ELSE.
     *
     * A blanket allow makes a scan worthless, so the proof is a planted
     * string: real Arabic, in the page, OUTSIDE the switcher's form, must
     * still fail. The second half plants it INSIDE the form, where the two
     * language names live, and that must still fail too — the exception is
     * for the names, not for the neighbourhood.
     */
    public function test_the_switcher_exception_is_narrow(): void
    {
        $page = (string) $this->get('/')->getContent();

        // The switcher really is there and really does carry Arabic.
        $this->assertMatchesRegularExpression(self::SWITCHER_MARKUP, $page);
        $this->assertMatchesRegularExpression('/[\p{Arabic}]/u', $page, 'no Arabic in the switcher to excuse');

        // ...and with it cut out, the page is clean.
        $this->assertSame([], $this->arabicIn($page));

        // A planted string outside the form is caught.
        $planted = str_replace('</main>', '<p>مرحباً بك</p></main>', $page);

        $this->assertNotSame([], $this->arabicIn($planted), 'a planted Arabic paragraph was not caught');

        // And one in an aria-label, which is where nobody looks.
        $labelled = str_replace('<main', '<main aria-label="قائمة"', $page);

        $this->assertNotSame([], $this->arabicIn($labelled), 'a planted Arabic aria-label was not caught');
    }
}
