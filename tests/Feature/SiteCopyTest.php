<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SettingGroups;
use App\Support\Settings;
use App\Support\SiteCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 2 — THE OWNER'S STRINGS, AND THE THREE WAYS THIS CAN GO WRONG.
 *
 *   1. A view asks for a key the registry does not have, and the KEY renders
 *      on the page. This is not hypothetical: converting the views found
 *      `work.heading` and `archive.heading` had been read from settings since
 *      they were written and never seeded, so both pages had silently been
 *      using a template literal and neither had a row to edit.
 *   2. A registry entry nothing reads — a settings field that does nothing,
 *      which is worse than a missing one because the owner edits it and waits.
 *   3. A blank value rendering an empty section, which Part 2f forbids.
 * ───────────────────────────────────────────────────────────────────────────
 */
class SiteCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
    }

    /** @return array<string, list<string>> key => the views that ask for it */
    private function keysUsedInViews(): array
    {
        $used = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($rii as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all(
                '/SiteCopy::get\(\s*[\'"]([a-z0-9_.]+)[\'"]/',
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $key) {
                $used[$key][] = $file->getFilename();
            }
        }

        return $used;
    }

    // ----------------------------------------------------------------- 1

    /** Every key a view asks for exists, so no page can render a key name. */
    public function test_every_key_a_view_asks_for_is_in_the_registry(): void
    {
        $known = SiteCopy::keys();
        $used = $this->keysUsedInViews();

        $this->assertNotEmpty($used, 'no SiteCopy keys were found in any view, so this proves nothing');

        $unknown = [];

        foreach (array_keys($used) as $key) {
            if (! in_array($key, $known, true)) {
                $unknown[] = $key.' (in '.implode(', ', array_unique($used[$key])).')';
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "these views ask for keys the registry does not have, so the KEY renders on the page:\n  "
            .implode("\n  ", $unknown)
        );
    }

    /** And every key in the registry is seeded as a row an owner can edit. */
    public function test_every_registry_key_has_a_settings_row(): void
    {
        $rows = \App\Models\Setting::query()->pluck('key')->all();

        $this->assertSame(
            [],
            array_values(array_diff(SiteCopy::keys(), $rows)),
            'these registry keys were not seeded, so the Settings page has no field for them'
        );
    }

    // ----------------------------------------------------------------- 2

    /**
     * EVERY SETTINGS ROW IS READ BY SOMETHING.
     *
     * A field in the panel that changes nothing is a worse defect than a
     * missing one: the owner edits it, saves, reloads the site and sees no
     * change, and concludes the panel is broken.
     *
     * Searched across views AND PHP, because some keys are read by a resolver
     * (seo.*) or a support class rather than by a template.
     */
    public function test_every_settings_key_is_read_somewhere(): void
    {
        $haystack = '';

        foreach ([resource_path('views'), app_path()] as $root) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($rii as $file) {
                if ($file->isDir()) {
                    continue;
                }

                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                // The registry itself lists every key by definition.
                if ($file->getFilename() === 'SiteCopy.php') {
                    continue;
                }

                $haystack .= (string) file_get_contents($file->getPathname());
            }
        }

        $unread = [];

        foreach (SiteCopy::keys() as $key) {
            if (! str_contains($haystack, "'".$key."'") && ! str_contains($haystack, '"'.$key.'"')) {
                $unread[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unread,
            "these settings are editable but nothing reads them — the owner would edit and wait:\n  "
            .implode("\n  ", $unread)
        );
    }

    // ----------------------------------------------------------------- 3

    /** A blank value falls back to the default, never to nothing. */
    public function test_a_blank_value_falls_back_rather_than_rendering_empty(): void
    {
        Settings::set('work.see_all', '');
        Settings::flush();

        $this->assertSame('See all work', SiteCopy::get('work.see_all'));

        // And a deleted row behaves the same way.
        \App\Models\Setting::query()->where('key', 'work.see_all')->delete();
        Settings::flush();

        $this->assertSame('See all work', SiteCopy::get('work.see_all'));
    }

    /** A key with no registry entry at all returns the key, which is findable. */
    public function test_an_unknown_key_returns_itself(): void
    {
        $this->assertSame('nothing.like.this', SiteCopy::get('nothing.like.this'));
    }

    /** An owner's wording wins over the default, in the active locale. */
    public function test_the_owners_wording_wins(): void
    {
        $setting = \App\Models\Setting::query()->where('key', 'work.see_all')->firstOrFail();
        $setting->setTranslation('value', 'en', 'Browse the whole portfolio');
        $setting->setTranslation('value', 'ar', 'استعرض كل الأعمال');
        $setting->save();
        Settings::flush();

        $this->app->setLocale('en');
        $this->assertSame('Browse the whole portfolio', SiteCopy::get('work.see_all'));

        $this->app->setLocale('ar');
        $this->assertSame('استعرض كل الأعمال', SiteCopy::get('work.see_all'));
    }

    // ----------------------------------------------------------------- groups

    /** Every key's group is one the Settings page knows how to title. */
    public function test_every_group_has_a_title(): void
    {
        $groups = array_unique(array_column(SiteCopy::defaults(), 'group'));
        $known = SettingGroups::order();

        $this->assertSame(
            [],
            array_values(array_diff($groups, $known)),
            'these groups would be titled by headline() on the raw key'
        );
    }

    /** And the grouping is by place, so no group holds more than it can show. */
    public function test_no_group_is_an_unreadable_dump(): void
    {
        $counts = array_count_values(array_column(SiteCopy::defaults(), 'group'));

        foreach ($counts as $group => $count) {
            $this->assertLessThanOrEqual(
                20,
                $count,
                "the '$group' group holds $count settings, which is a list rather than a section"
            );
        }
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * PART 3c — EVERY PROSE SETTING SHIPS WITH REAL ARABIC.
     *
     * The registry grew `ar` values in this task, but 63 rows pre-dated that
     * and had none — so a fresh install rendered them through the fallback,
     * which means English on an Arabic page. The 3d scan did not catch it
     * because the scan SETS the Arabic itself in order to isolate code
     * strings from owner strings; this is the other half of that guarantee.
     *
     * Three rows are exempt and each is named, because an exemption list that
     * is a count rather than a list is where the next gap hides.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_every_prose_setting_ships_with_arabic(): void
    {
        /*
         *   attribution.name    a person's name. A. Marsden is A. Marsden in
         *                       Arabic; transliterating somebody's name for
         *                       them is not translation.
         *   contact.recipient   an email address.
         *   footer.social_3_*   the empty third slot: no label, no url, no
         *                       translation to have.
         */
        $exempt = [
            'attribution.name',
            'contact.recipient',
            'footer.social_3_label',
            'footer.social_3_url',
        ];

        $missing = [];

        foreach (SiteCopy::defaults() as $row) {
            if (in_array($row['key'], $exempt, true)) {
                continue;
            }

            // A url or a media id is not prose.
            if (str_ends_with($row['key'], '_url') || str_ends_with($row['key'], '_media_id')) {
                continue;
            }

            if ($row['value'] === null) {
                continue;
            }

            if (($row['ar'] ?? null) === null) {
                $missing[] = $row['key'];
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these settings would render English on an Arabic page:\n  ".implode("\n  ", $missing)
        );
    }

    /** And the seeded Arabic really reaches the table. */
    public function test_the_arabic_defaults_reach_the_database(): void
    {
        $untranslated = \App\Models\Setting::query()->get()
            ->reject(fn ($setting): bool => str_ends_with($setting->key, '_url')
                || str_ends_with($setting->key, '_media_id')
                || in_array($setting->key, ['attribution.name', 'contact.recipient', 'footer.social_3_label'], true))
            ->reject(fn ($setting): bool => Settings::isTranslated($setting->key, 'ar'))
            ->pluck('key')
            ->all();

        $this->assertSame([], array_values($untranslated));
    }

    /** Both locales are seeded for every string an owner reads. */
    public function test_every_copy_key_is_seeded_in_both_locales(): void
    {
        $untranslated = [];

        foreach (SiteCopy::defaults() as $row) {
            // Rows that are not prose: a URL, a media id.
            if ($row['value'] === null || ! array_key_exists('ar', $row)) {
                continue;
            }

            if ($row['ar'] === null) {
                continue;
            }

            if (! Settings::isTranslated($row['key'], 'ar')) {
                $untranslated[] = $row['key'];
            }
        }

        $this->assertSame([], $untranslated, 'these keys have an Arabic default that did not reach the table');
    }
}
