<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header's globe language switcher and mobile menu (master directive,
 * Phase 2). What can be asserted from the server: both are <details>, so
 * they open with scripting off; the globe wraps the one locale form; the
 * menu carries the navigation. Escape, outside-tap and the step-aside on
 * scroll are browser behaviour, checked by bin/browser-check.mjs.
 */
class HeaderControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->seed(\Database\Seeders\NavigationSeeder::class);
        Settings::flush();
    }

    public function test_the_globe_is_a_disclosure_around_the_locale_form_in_both_languages(): void
    {
        foreach (['/' => ['Language: English', 'EN'], '/ar' => ['اللغة: العربية', 'AR']] as $url => [$label, $code]) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<details class="globe" data-disclosure>\s*<summary class="globe__toggle"\s+aria-label="'.preg_quote($label, '#').'">#u', $html, $url);
            $this->assertStringContainsString('<span class="globe__code" aria-hidden="true">'.$code.'</span>', $html);

            // Inside it, the one form: a real POST with a button per language.
            $globe = substr($html, (int) strpos($html, '<details class="globe"'));
            $globe = substr($globe, 0, (int) strpos($globe, '</details>'));
            $this->assertStringContainsString('action="'.route('locale.store').'"', $globe);
            $this->assertSame(2, substr_count($globe, 'name="locale"'));
            $this->assertStringContainsString('aria-current="true"', $globe);
        }
    }

    public function test_the_menu_is_a_disclosure_carrying_the_navigation(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<details class="site-menu" data-disclosure>', $html);

        $menu = substr($html, (int) strpos($html, '<details class="site-menu"'));
        $menu = substr($menu, 0, (int) strpos($menu, '</details>'));

        $inline = substr($html, (int) strpos($html, '<ul role="list" class="site-nav__list">'));
        $inline = substr($inline, 0, (int) strpos($inline, '</ul>'));

        preg_match_all('#href="([^"]+)"#', $inline, $inlineLinks);
        preg_match_all('#href="([^"]+)"#', $menu, $menuLinks);

        $this->assertNotEmpty($inlineLinks[1]);
        $this->assertSame($inlineLinks[1], $menuLinks[1], 'the menu and the inline navigation offer different links');
        $this->assertStringContainsString('<span class="site-menu__open">Menu</span>', $menu);
    }
}
