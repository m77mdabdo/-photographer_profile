<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Locale;
use App\Support\Settings as SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Part C — settings are a Page, and the page edits both locales at once.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The settings table is a fixed vocabulary, seeded rather than created
         * by a factory — there is no factory, because inventing a key produces
         * a row nothing reads. See SettingPolicy.
         */
        SettingsStore::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        SettingsStore::flush();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    /** It is a Page. A Resource would be a table of keys. */
    public function test_settings_are_a_page_not_a_resource(): void
    {
        $this->assertTrue(is_subclass_of(SettingsPage::class, \Filament\Pages\Page::class));
        $this->assertFalse(is_subclass_of(SettingsPage::class, \Filament\Resources\Resource::class));

        $this->get('/studio/settings')->assertOk();
    }

    /** Settings cannot be created or destroyed, in bulk or singly. */
    public function test_a_setting_is_neither_creatable_nor_deletable(): void
    {
        $this->assertFalse(Gate::has('create'), 'no global create ability should shadow the policy');

        foreach (['delete', 'deleteAny', 'forceDeleteAny', 'restoreAny'] as $ability) {
            $this->assertTrue(
                Gate::inspect($ability, Setting::class)->denied(),
                "settings should refuse $ability"
            );
        }
    }

    /**
     * SAVING ENGLISH DOES NOT DESTROY ARABIC — the same guarantee as every
     * resource, through a different code path, so it needs its own test.
     */
    public function test_saving_does_not_destroy_the_other_locale(): void
    {
        $setting = Setting::query()->firstOrFail();

        $setting->setTranslation('value', Locale::DEFAULT, 'The English value');
        $setting->setTranslation('value', 'ar', 'القيمة العربية');
        $setting->save();

        Livewire::test(SettingsPage::class)
            ->assertOk()
            /*
             * THE PATH A BROWSER SENDS, not a convenient one. Livewire splits
             * a dotted path with data_set(), which is exactly how the first
             * version of this page lost every edit — see SettingsPage::$values.
             */
            ->set('values.'.SettingsPage::token($setting->key).'.'.Locale::DEFAULT, 'Rewritten in English')
            ->call('save');

        $setting->refresh();

        $this->assertSame('Rewritten in English', $setting->getTranslation('value', Locale::DEFAULT, fallback: false));
        $this->assertSame('القيمة العربية', $setting->getTranslation('value', 'ar', fallback: false));
    }

    /**
     * AND CLEARING A FIELD REALLY CLEARS IT.
     *
     * The mirror image of the test above, and the reason the page writes the
     * whole map rather than one locale: setTranslation() with an empty string
     * on one locale would be indistinguishable from "left alone", so an editor
     * deleting an Arabic tagline would watch it come back.
     */
    public function test_clearing_one_locale_clears_only_that_locale(): void
    {
        $setting = Setting::query()->firstOrFail();

        $setting->setTranslation('value', Locale::DEFAULT, 'The English value');
        $setting->setTranslation('value', 'ar', 'القيمة العربية');
        $setting->save();

        Livewire::test(SettingsPage::class)
            ->set('values.'.SettingsPage::token($setting->key).'.ar', '')
            ->call('save');

        $setting->refresh();

        $this->assertNull($setting->getTranslation('value', 'ar', fallback: false));
        $this->assertSame('The English value', $setting->getTranslation('value', Locale::DEFAULT, fallback: false));
    }

    /** Both locales are on screen, and the Arabic side is right-to-left. */
    public function test_both_locales_are_editable_on_the_page(): void
    {
        $html = $this->get('/studio/settings')->assertOk()->getContent();

        $this->assertStringContainsString('English', $html);
        $this->assertStringContainsString('العربية', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    /**
     * NO SETTING KEY CONTAINS A DOUBLE UNDERSCORE, which is what makes the
     * token round trip exact rather than nearly exact.
     */
    public function test_the_token_round_trip_is_exact_for_every_key(): void
    {
        $keys = Setting::query()->pluck('key')->all();

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertStringNotContainsString('__', $key, "the key $key would not survive tokenising");
            $this->assertSame($key, SettingsPage::keyFor(SettingsPage::token($key)));
        }
    }

    /** A save flushes the cache, or the site keeps serving the old value. */
    public function test_a_save_flushes_the_settings_cache(): void
    {
        SettingsStore::set('site.name', 'Before');
        SettingsStore::flush();

        $this->assertSame('Before', SettingsStore::get('site.name'));

        Livewire::test(SettingsPage::class)
            ->set('values.'.SettingsPage::token('site.name').'.'.Locale::DEFAULT, 'After')
            ->call('save');

        $this->assertSame('After', SettingsStore::get('site.name'));
    }
}
