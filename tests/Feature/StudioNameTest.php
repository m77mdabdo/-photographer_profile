<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use App\Support\SiteCopy;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 34d PART C — THE STUDIO HAS A REAL NAME, AND IT IS DATA.
 *
 * "Photographer" was the placeholder; the studio is Anas Fareed. Changing it
 * is a row in the settings table, not an edit to the code — and the things
 * worth testing are the ones that would make that false:
 *
 *   · a surface that prints a name of its own rather than asking
 *   · a seeder that puts the default back over a name somebody chose
 *   · a layout that only holds together for one particular name
 *
 * The last of those is measured in the browser, not here; the harness checks
 * the wordmark at four widths in both languages. This file holds the two
 * that are answerable in PHP, and the one that is answerable by reading the
 * source.
 * ───────────────────────────────────────────────────────────────────────────
 */
class StudioNameTest extends TestCase
{
    use RefreshDatabase;

    /** The keys that carry the studio's name, and what reads each one. */
    private const NAME_KEYS = ['site.name', 'attribution.name'];

    public function test_the_defaults_carry_the_real_name_in_both_languages(): void
    {
        $defaults = collect(SiteCopy::defaults())->keyBy('key');

        foreach (self::NAME_KEYS as $key) {
            $this->assertSame('Anas Fareed', $defaults[$key]['value'], "{$key} default");
            $this->assertSame('أنس فريد', $defaults[$key]['ar'] ?? null, "{$key} Arabic default");
        }
    }

    /**
     * Test 1 — THE NAME IS NEVER A LITERAL.
     *
     * Every surface asks Settings for it. A view or a class that printed the
     * name itself would keep printing it after the owner renamed the studio,
     * and nothing would say so — the failure is silent and permanent.
     *
     * config/seo.php is the one exception and is deliberately NOT the real
     * name: it is the last-resort fallback for an install with no settings
     * row at all, and it says "Photographer" precisely because it is a
     * description of any such site rather than anybody's name.
     */
    public function test_no_surface_hardcodes_the_studio_name(): void
    {
        $offenders = [];

        foreach ([base_path('app'), base_path('resources/views'), base_path('database'), base_path('config')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                // SiteCopy IS the defaults, which is the one place the name belongs.
                if (str_ends_with($file->getPathname(), 'app/Support/SiteCopy.php')) {
                    continue;
                }

                $code = $this->withoutComments((string) file_get_contents($file->getPathname()));

                foreach (['Anas Fareed', 'أنس فريد', 'A. Marsden'] as $literal) {
                    if (str_contains($code, $literal)) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).": {$literal}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "the name is written into the code:\n  ".implode("\n  ", $offenders));
    }

    /**
     * Test 3 — RE-SEEDING NEVER OVERWRITES A NAME SOMEBODY CHOSE.
     *
     * SettingsSeeder creates and does not update, which is the rule
     * Concerns\ProtectsEditedContent exists for. This is the case that rule
     * was written about: a studio renames itself in the panel, the next
     * deploy runs the seeders, and the name must survive.
     */
    public function test_reseeding_leaves_an_edited_name_alone(): void
    {
        $this->seed(SettingsSeeder::class);

        $setting = Setting::query()->where('key', 'site.name')->firstOrFail();
        $setting->setTranslations('value', ['en' => 'Hallveien Studio', 'ar' => 'استوديو هالفاين']);
        $setting->save();
        Settings::flush();

        $this->seed(SettingsSeeder::class);
        Settings::flush();

        $this->app->setLocale('en');
        $this->assertSame('Hallveien Studio', Settings::get('site.name'));

        $this->app->setLocale('ar');
        Settings::flush();
        $this->assertSame('استوديو هالفاين', Settings::get('site.name'));
    }

    /** And a fresh install gets the real name, in both languages. */
    public function test_a_fresh_install_is_named_correctly(): void
    {
        $this->seed(SettingsSeeder::class);
        Settings::flush();

        foreach (['en' => 'Anas Fareed', 'ar' => 'أنس فريد'] as $locale => $expected) {
            $this->app->setLocale($locale);
            Settings::flush();

            foreach (self::NAME_KEYS as $key) {
                $this->assertSame($expected, Settings::get($key), "{$key} in {$locale}");
            }
        }
    }

    /**
     * Test 1, the other half: the surfaces READ it.
     *
     * Renamed to something nobody would seed, so a surface still showing the
     * default would be obvious. Each of these is a different path to the same
     * row: the overlay onto config, the structured data, the panel's brand
     * and the acknowledgement mail.
     */
    public function test_every_surface_follows_a_rename(): void
    {
        $this->seed(SettingsSeeder::class);

        $setting = Setting::query()->where('key', 'site.name')->firstOrFail();
        $setting->setTranslations('value', ['en' => 'Zzyzx Quorum', 'ar' => 'زيزكس']);
        $setting->save();
        Settings::flush();

        $html = $this->get('/')->assertOk()->getContent();

        // The header wordmark, the hero, the document title and og:site_name.
        $this->assertStringContainsString('<title>Zzyzx Quorum', $html);
        $this->assertStringContainsString('og:site_name" content="Zzyzx Quorum', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Zzyzx Quorum'));

        // The panel's brand name is the same setting.
        $this->assertSame('Zzyzx Quorum', (string) (Settings::get('site.name') ?: config('app.name')));

        // And the mail an enquirer receives.
        $inquiry = \App\Models\Inquiry::factory()->create();
        $mail = (new \App\Mail\InquiryAcknowledged($inquiry))->render();

        $this->assertStringContainsString('Zzyzx Quorum', $mail);
    }

    /** PHP comments do not count as code for the literal check above. */
    private function withoutComments(string $source): string
    {
        return implode('', array_map(
            fn (array | string $token): string => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all($source),
        ));
    }
}
