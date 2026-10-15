<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Locale;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 34b — THE INTERFACE LANGUAGE, FROM INSIDE THE PANEL.
 *
 * The panel was fully translated in Part F and had no way to change its own
 * language: the cookie SetLocale reads was written only by the public site's
 * footer switcher, so working in Arabic began with leaving the panel.
 *
 * What is asserted here is that the switcher uses THE SAME MECHANISM — the
 * same endpoint, writing the same cookie, read by the same middleware — that
 * it returns to the page it was used on INCLUDING its query string, and that
 * direction and content never disagree afterwards on any panel screen, which
 * is the Task 32 rule applied to the surface Task 32 exempted.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PanelLocaleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------- A

    /** It is on the page, it is a form, and its buttons are buttons. */
    public function test_the_switcher_is_on_every_panel_page(): void
    {
        $html = $this->actingAs($this->owner())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('class="studio-locale"', $html);
        $this->assertStringContainsString('action="'.route('locale.store').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);

        foreach (Locale::codes() as $code) {
            $this->assertMatchesRegularExpression(
                '/<button[^>]*name="locale"[^>]*value="'.$code.'"/',
                $html,
                "no submit button for {$code}",
            );
        }

        /*
         * The current language is marked in the markup rather than only in
         * the stylesheet, because that is what a screen reader reads and what
         * the browser harness compares against <html lang>.
         */
        $this->assertMatchesRegularExpression('/value="en"[^>]*aria-current="true"/s', $html);
    }

    /** Every language is named in itself, and announced in the reader's. */
    public function test_each_option_is_named_twice(): void
    {
        $html = $this->actingAs($this->owner())->get('/admin')->assertOk()->getContent();

        // In itself: somebody who reads only Arabic must find the button.
        $this->assertStringContainsString('العربية', $html);
        $this->assertStringContainsString('English', $html);

        // And in the panel's current language, for assistive technology.
        $this->assertStringContainsString(Locale::translatedName('ar'), $html);

        $arabic = $this->actingAs($this->owner())
            ->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('الإنجليزية', $arabic, 'the English option is not announced in Arabic');
    }

    // ----------------------------------------------------------------- 1

    /** Test 1: the same cookie the public switcher writes. */
    public function test_it_writes_the_same_cookie_as_the_public_switcher(): void
    {
        $fromPanel = $this->actingAs($this->owner())
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/admin/projects']);

        $fromPublic = $this->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/work']);

        foreach ([$fromPanel, $fromPublic] as $response) {
            $response->assertCookie(Locale::COOKIE, 'ar', false);
        }

        $panelCookie = collect($fromPanel->headers->getCookies())
            ->firstWhere(fn ($c): bool => $c->getName() === Locale::COOKIE);
        $publicCookie = collect($fromPublic->headers->getCookies())
            ->firstWhere(fn ($c): bool => $c->getName() === Locale::COOKIE);

        $this->assertNotNull($panelCookie);
        $this->assertNotNull($publicCookie);

        // Name, path, value, http-only flag and same-site policy, not just the value.
        $this->assertSame($publicCookie->getPath(), $panelCookie->getPath());
        $this->assertSame($publicCookie->isHttpOnly(), $panelCookie->isHttpOnly());
        $this->assertSame($publicCookie->getSameSite(), $panelCookie->getSameSite());

        // And it is the one SetLocale reads.
        $this->assertSame(Locale::COOKIE, $panelCookie->getName());
    }

    // ----------------------------------------------------------------- 2

    /** Test 2: the current panel URL survives, query string and all. */
    public function test_it_returns_to_the_same_panel_url_with_its_query(): void
    {
        $owner = $this->owner();

        $deepLink = '/admin/projects?tableFilters[status][value]=draft&tableSortColumn=updated_at';

        $this->actingAs($owner)
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => $deepLink])
            ->assertRedirect($deepLink)
            ->assertCookie(Locale::COOKIE, 'ar', false);

        // A record's edit screen, which is the case the brief names.
        $this->actingAs($owner)
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/admin/projects/7/edit'])
            ->assertRedirect('/admin/projects/7/edit');

        /*
         * NO PREFIX ON THE WAY BACK. /ar/admin is a door that writes the
         * cookie and redirects; sending an editor through it would be a
         * second round trip to arrive where this redirect already goes.
         */
        $this->actingAs($owner)
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/admin'])
            ->assertRedirect('/admin');
    }

    /** The public site keeps the behaviour it had: prefixed, query dropped. */
    public function test_the_public_switcher_is_unchanged(): void
    {
        $this->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/work?page=4'])
            ->assertRedirect('/ar/work');

        $this->post(route('locale.store'), ['locale' => 'en', 'return_to' => '/ar/work/salt-line'])
            ->assertRedirect('/work/salt-line');
    }

    /** A return path is never somewhere else's address. */
    public function test_it_refuses_an_off_site_return(): void
    {
        foreach (['//evil.example', 'https://evil.example/admin', '', 'admin'] as $candidate) {
            $this->actingAs($this->owner())
                ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => $candidate])
                ->assertRedirect(Locale::pathFor('ar', '/'));
        }
    }

    /** And an unsupported locale is rejected rather than stored. */
    public function test_it_refuses_a_locale_the_site_does_not_have(): void
    {
        $this->actingAs($this->owner())
            ->post(route('locale.store'), ['locale' => 'fr', 'return_to' => '/admin'])
            ->assertSessionHasErrors('locale')
            ->assertCookieMissing(Locale::COOKIE);
    }

    // ----------------------------------------------------------------- 3

    /**
     * Test 3: direction and content agree on EVERY panel screen, in both
     * languages — the Task 32 rule, on the surface Task 32 exempted.
     *
     * The list is derived from the resources directory rather than written
     * out, so a screen added next year is covered the day it appears.
     *
     * The provider yields CLASS NAMES and a couple of literal paths, never a
     * resolved URL: a PHPUnit data provider runs before the framework exists,
     * and asking a resource for its address there is "A facade root has not
     * been set" — the same trap CrudPathsTest's provider documents.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('panelScreens')]
    public function test_direction_and_language_agree_on_every_panel_screen(string $screen): void
    {
        Filament::setCurrentPanel('studio');

        $owner = $this->owner();

        $path = str_starts_with($screen, '/')
            ? $screen
            : $screen::getUrl('index', panel: 'studio');

        foreach (Locale::codes() as $code) {
            $html = $this->actingAs($owner)
                ->withUnencryptedCookie(Locale::COOKIE, $code)
                ->get($path)
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString(
                'dir="'.Locale::direction($code).'"',
                $html,
                "{$path} is not ".Locale::direction($code)." in {$code}",
            );
            $this->assertStringContainsString(
                'lang="'.Locale::html($code).'"',
                $html,
                "{$path} does not declare lang={$code}",
            );

            /*
             * And the switcher agrees with the document. A control that says
             * English on an Arabic page is worse than no control.
             */
            $this->assertMatchesRegularExpression(
                '/value="'.$code.'"[^>]*aria-current="true"/s',
                $html,
                "{$path}: the switcher does not show {$code} as current",
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function panelScreens(): array
    {
        $screens = ['dashboard' => ['/admin'], 'settings' => ['/admin/settings']];

        foreach (glob(dirname(__DIR__, 3).'/app/Filament/Resources/*/*Resource.php') as $file) {
            $class = 'App\\Filament\\Resources\\'
                .basename(dirname($file)).'\\'
                .basename($file, '.php');

            $screens[class_basename($class)] = [$class];
        }

        ksort($screens);

        return $screens;
    }

    // ----------------------------------------------------------------- C

    /**
     * C — the direction is decided by the server, on the first byte, so there
     * is nothing to flash.
     *
     * Asserted two ways, because "no flash" is a claim about a moment no
     * screenshot catches. The response that comes back from the switch
     * already carries the new direction — it is a redirect to a page rendered
     * by SetLocale, not a page that corrects itself afterwards — and no
     * JavaScript in this project writes `dir` at all, so there is no second
     * writer that could disagree with the first.
     */
    public function test_the_direction_is_server_decided_on_the_first_byte(): void
    {
        $owner = $this->owner();

        $redirect = $this->actingAs($owner)
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/admin/projects'])
            ->assertRedirect('/admin/projects');

        // Follow it exactly as a browser would: the cookie this set, nothing else.
        $html = $this->actingAs($owner)
            ->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get('/admin/projects')
            ->assertOk()
            ->getContent();

        $opening = substr($html, 0, (int) strpos($html, '</head>'));

        $this->assertStringContainsString('dir="rtl"', $opening, 'the direction is not in the opening markup');
        $this->assertStringContainsString('lang="ar"', $opening);

        // Nothing in our JavaScript sets a direction after the fact.
        foreach ($this->projectScripts() as $file => $source) {
            $this->assertStringNotContainsString('documentElement.dir', $source, "{$file} writes dir");
            $this->assertDoesNotMatchRegularExpression(
                '/setAttribute\(\s*[\'"]dir[\'"]/',
                $source,
                "{$file} writes dir",
            );
        }
    }

    /** @return array<string, string> */
    private function projectScripts(): array
    {
        $sources = [];
        $root = base_path('resources/js');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $sources[$file->getFilename()] = (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertNotEmpty($sources);

        return $sources;
    }

    // ----------------------------------------------------------------- E

    /**
     * E — the interface language and the content language are two controls
     * and must not be mistakable for one another.
     *
     * Both name the same two languages, so the distinction is carried by the
     * things that differ: a globe against a document, the word "Interface"
     * against the word "Content", and the chrome against the form.
     */
    public function test_the_interface_control_is_not_the_content_control(): void
    {
        $html = $this->actingAs($this->owner())->get('/admin')->assertOk()->getContent();

        // The chrome's control: globe, and the word, visibly and announced.
        $this->assertStringContainsString('studio-locale__icon', $html);
        $this->assertStringContainsString(__('studio.locale.interface'), $html);
        $this->assertStringContainsString(__('studio.locale.interface_legend'), $html);

        // It is in the topbar, outside the page's content.
        $this->assertMatchesRegularExpression('/fi-topbar.*studio-locale/s', $html);
    }

    /** The content tabs say they are the content's language, and carry a document. */
    public function test_the_content_tabs_declare_themselves(): void
    {
        Filament::setCurrentPanel('studio');
        $this->actingAs($this->owner());

        $schema = \App\Filament\Resources\Projects\ProjectResource::form(
            \Filament\Schemas\Schema::make(
                Livewire::test(\App\Filament\Resources\Projects\Pages\CreateProject::class)->instance()
            )
        );

        $tabs = $this->findLocaleTabs($schema->getComponents());

        $this->assertNotNull($tabs, 'the project form has no locale tabs');
        $this->assertSame(__('studio.locale.content_legend'), $tabs->getLabel());

        foreach ($tabs->getChildSchema()->getComponents() as $tab) {
            $this->assertNotNull($tab->getIcon(), 'a locale tab carries no icon');
            $this->assertStringContainsString('document', (string) $this->iconName($tab->getIcon()));
        }
    }

    /**
     * The two controls do not share a word beyond the language names: the
     * chrome says "Interface", the form says "Content", in both locales.
     */
    public function test_the_two_controls_are_worded_apart(): void
    {
        foreach (Locale::codes() as $code) {
            $this->app->setLocale($code);

            $interface = __('studio.locale.interface_legend');
            $content = __('studio.locale.content_legend');

            $this->assertNotSame($interface, $content, "the two legends read the same in {$code}");
            $this->assertNotSame(__('studio.locale.interface'), '', "no interface label in {$code}");
        }
    }

    private function findLocaleTabs(array $components): ?\Filament\Schemas\Components\Tabs
    {
        foreach ($components as $component) {
            if ($component instanceof \Filament\Schemas\Components\Tabs) {
                return $component;
            }

            foreach ($component->getChildSchemas() as $child) {
                $found = $this->findLocaleTabs($child->getComponents());

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function iconName(mixed $icon): string
    {
        return $icon instanceof \Filament\Support\Icons\Heroicon ? $icon->value : (string) $icon;
    }
}
