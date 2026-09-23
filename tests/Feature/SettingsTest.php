<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Admin\SettingsManager;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
    }

    public function test_the_whole_table_is_one_query_however_many_keys_are_read(): void
    {
        $keys = Setting::query()->pluck('key')->all();

        Settings::flush();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        // Twenty reads, one query. This is the entire point of the design:
        // a page that renders twenty strings must not cost twenty round trips.
        foreach ($keys as $key) {
            Settings::get($key);
        }

        $this->assertSame(1, $queries, 'the settings map must be fetched exactly once');
        $this->assertGreaterThan(15, Setting::query()->count(), 'there are enough keys for this to matter');
    }

    public function test_a_write_invalidates_the_cache(): void
    {
        $this->assertSame('Photographer', Settings::get('site.name'));

        Settings::set('site.name', 'Marsden Studio');

        // No manual flush: the model's own saved event does it, so a setting
        // changed from a command or a seeder invalidates too.
        $this->assertSame('Marsden Studio', Settings::get('site.name'));
    }

    public function test_an_empty_value_falls_through_to_the_config_default(): void
    {
        Settings::set('site.name', null);
        Settings::overlayConfig();

        /*
         * PRECEDENCE: the table wins when it holds something, and the config
         * file is the fallback of last resort. An empty row means "I have no
         * opinion", not "render nothing" — a blank studio name must not blank
         * the page.
         */
        $this->assertSame('Photographer', config('seo.site_name'));
    }

    public function test_the_table_overrides_config_for_the_keys_they_share(): void
    {
        Settings::set('site.name', 'Marsden Studio');
        Settings::set('seo.description', 'Portraits made slowly.');

        Settings::overlayConfig();

        $this->assertSame('Marsden Studio', config('seo.site_name'));
        $this->assertSame('Marsden Studio', config('seo.default_title'));
        $this->assertSame('Portraits made slowly.', config('seo.default_description'));
    }

    public function test_a_renamed_studio_reaches_every_corner_of_the_page(): void
    {
        Settings::set('site.name', 'Marsden Studio');
        Settings::overlayConfig();

        $html = $this->get('/')->assertOk()->getContent();

        // The wordmark, the header mark, the og:site_name and the footer.
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'Marsden Studio'));
        $this->assertStringContainsString('<h1 class="hero__wordmark" id="hero-title">Marsden Studio</h1>', $html);
        $this->assertStringNotContainsString('>Photographer<', $html);
    }

    public function test_a_missing_settings_table_does_not_stop_the_site_booting(): void
    {
        Settings::flush();
        DB::statement('DROP TABLE settings');

        /*
         * The overlay must be survivable. A site whose home page 500s because
         * a migration has not run yet is worse than one that renders its
         * built-in defaults.
         */
        Settings::overlayConfig();

        $this->assertSame('Photographer', config('seo.site_name'));
        $this->get('/')->assertOk();
    }

    public function test_the_admin_panel_groups_every_key_and_saves_them(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(SettingsManager::class)
            ->assertSee('Identity')
            ->assertSee('Navigation labels')
            ->assertSee('site.name')
            ->set('values.'.Setting::query()->where('key', 'site.statement')->value('id'), 'A new sentence about the work.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('A new sentence about the work.', Settings::get('site.statement'));

        // Every seeded key is on the screen; a key nobody can edit is a key
        // that will drift.
        $rendered = Livewire::actingAs($owner)->test(SettingsManager::class)->html();

        foreach (Setting::query()->pluck('key') as $key) {
            $this->assertStringContainsString($key, $rendered, "{$key} is not editable");
        }
    }

    public function test_blanking_a_field_stores_null_rather_than_an_empty_string(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(SettingsManager::class)
            ->set('values.'.Setting::query()->where('key', 'site.location')->value('id'), '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'site.location')->value('value'));
        $this->assertNull(Settings::get('site.location'));
    }

    public function test_a_visitor_cannot_reach_the_settings_panel(): void
    {
        $this->get('/admin/settings')->assertRedirect();
    }

    public function test_the_panel_offers_no_way_to_invent_or_delete_a_key(): void
    {
        /*
         * A key is an address the templates ask for. Creating one produces a
         * row nothing reads; deleting one silently drops a template back to
         * its config fallback, or to nothing at all.
         */
        $source = (string) file_get_contents(app_path('Livewire/Admin/SettingsManager.php'));

        $this->assertStringNotContainsString('public function create', $source);
        $this->assertStringNotContainsString('public function delete', $source);

        $policy = (string) file_get_contents(app_path('Policies/SettingPolicy.php'));

        $this->assertStringNotContainsString('function create', $policy);
        $this->assertStringNotContainsString('function delete', $policy);
    }
}
