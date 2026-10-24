<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\UpgradeSiteCopy;
use App\Models\Client;
use App\Models\Project;
use App\Models\Service;
use App\Models\Setting;
use App\Support\Settings;
use App\Support\SiteCopy;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\DemoServicesSeeder;
use Database\Seeders\ServicesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The commercial rewrite (master directive, 22 Oct 2026): what the site says
 * about the business, how a running site is moved onto it without losing an
 * owner's edits, and the line between demo content and real content.
 */
class CommercialContentTest extends TestCase
{
    use RefreshDatabase;

    /** Seeds the settings as a site that predates the rewrite would have them. */
    private function seedPreviousDefaults(): void
    {
        $this->seed(SettingsSeeder::class);

        foreach (UpgradeSiteCopy::PREVIOUS as $key => [$en, $ar]) {
            $setting = Setting::query()->where('key', $key)->firstOrFail();
            $setting->setTranslations('value', ['en' => $en, 'ar' => $ar]);
            $setting->save();
        }

        Settings::flush();
    }

    public function test_the_upgrade_replaces_untouched_defaults_and_keeps_owner_edits(): void
    {
        $this->seedPreviousDefaults();

        // The owner reworded one value, in English only.
        $edited = Setting::query()->where('key', 'contact.heading')->firstOrFail();
        $edited->setTranslation('value', 'en', 'Write to the studio');
        $edited->save();
        Settings::flush();

        $this->artisan('site:upgrade-copy')->assertSuccessful();
        Settings::flush();

        // Untouched values moved to the new defaults, in both languages.
        $this->assertSame('Request a quote', Settings::get('closing.cta'));
        $this->assertSame('اطلب عرض سعر', Setting::query()->where('key', 'closing.cta')->first()->getTranslation('value', 'ar', false));

        // The owner's English stayed; the untouched Arabic beside it moved.
        $heading = Setting::query()->where('key', 'contact.heading')->first();
        $this->assertSame('Write to the studio', $heading->getTranslation('value', 'en', false));
        $this->assertSame('احجز جلسة تصوير أو اطلب عرض سعر', $heading->getTranslation('value', 'ar', false));

        // A default that is now empty is removed, not left behind.
        $this->assertNull(Setting::query()->where('key', 'site.location')->first()->getTranslation('value', 'en', false));

        // And a second run changes nothing.
        $this->artisan('site:upgrade-copy')->expectsOutputToContain('0 value(s) updated, 1 owner edit(s) kept.')->assertSuccessful();
    }

    public function test_the_previous_defaults_are_the_ones_the_upgrade_replaces(): void
    {
        // Every key the upgrade knows is still a registry key with a new default.
        $keys = SiteCopy::keys();

        foreach (array_keys(UpgradeSiteCopy::PREVIOUS) as $key) {
            $this->assertContains($key, $keys, "{$key} is upgraded but no longer in SiteCopy");
        }
    }

    public function test_the_six_services_seed_in_both_languages_with_no_invented_price(): void
    {
        $this->seed(ServicesSeeder::class);

        $services = Service::query()->published()->orderBy('sort_order')->get();

        $this->assertSame([
            'advertising-and-campaign-photography',
            'product-photography',
            'studio-product-photography',
            'interiors-and-architecture-photography',
            'commercial-location-photography',
            'brand-content-and-creative-production',
        ], $services->pluck('slug')->all());

        foreach ($services as $service) {
            $this->assertFalse($service->is_demo);
            $this->assertNull($service->price_from_pence, "{$service->slug} carries a price nobody gave");

            foreach (['name', 'summary', 'description', 'cta_label'] as $field) {
                $this->assertNotEmpty($service->getTranslation($field, 'en', false), "{$service->slug} has no English {$field}");
                $this->assertNotEmpty($service->getTranslation($field, 'ar', false), "{$service->slug} has no Arabic {$field}");
            }
        }

        // Both locales render the index and every service page.
        foreach ($services as $service) {
            $this->get('/services/'.$service->slug)->assertOk()->assertSee($service->getTranslation('name', 'en'));
            $this->get('/ar/services/'.$service->slug)->assertOk()->assertSee($service->getTranslation('name', 'ar'));
        }
    }

    public function test_the_services_seeder_never_overwrites_or_resurrects(): void
    {
        $this->seed(ServicesSeeder::class);

        $edited = Service::query()->where('slug', 'product-photography')->firstOrFail();
        $edited->setTranslation('summary', 'en', 'The owner wrote this.');
        $edited->save();

        Service::query()->where('slug', 'commercial-location-photography')->firstOrFail()->delete();

        $this->seed(ServicesSeeder::class);

        $this->assertSame('The owner wrote this.', $edited->fresh()->getTranslation('summary', 'en'));
        $this->assertTrue(Service::withTrashed()->where('slug', 'commercial-location-photography')->first()->trashed());
        $this->assertSame(6, Service::withTrashed()->count());
    }

    public function test_demo_seeders_refuse_to_run_outside_local(): void
    {
        $this->app['env'] = 'production';

        // Called directly: `db:seed` in production stops for a confirmation,
        // and what is under test is the seeder's own refusal.
        (new DemoServicesSeeder)->run();
        (new DemoContentSeeder)->run();

        $this->assertSame(0, Service::query()->count(), 'demo services reached a production database');
        $this->assertSame(0, Project::query()->count(), 'demo projects reached a production database');
    }

    public function test_no_demo_client_list_is_seeded(): void
    {
        $this->seed(DemoContentSeeder::class);

        $this->assertSame(0, Client::query()->count(), 'the demo seeder invented clients again');
    }

    /** The claims the old copy made, which nobody established. */
    public function test_the_public_pages_make_no_unsupported_claims(): void
    {
        $this->seed(SettingsSeeder::class);
        $this->seed(ServicesSeeder::class);
        Settings::flush();

        $forbidden = ['Gentlewoman', 'Aperture', 'Northumberland', 'Newcastle', 'darkroom', 'trained as a printer',
            'نورثمبرلاند', 'نيوكاسل', 'غرفة التحميض'];

        foreach (['/', '/about', '/services', '/contact', '/ar', '/ar/about', '/ar/services', '/ar/contact'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            foreach ($forbidden as $claim) {
                $this->assertStringNotContainsStringIgnoringCase($claim, strip_tags($html), "{$url} still says \"{$claim}\"");
            }
        }

        // And the business it does describe.
        $this->get('/')->assertSee('Commercial photography for products, spaces and campaigns', false);
        $this->get('/about')->assertSee('commercial photographer', false);
    }
}
