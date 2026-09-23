<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * The defaults the site needs in order to render at all.
     *
     * These are starting values, not constants: every one of them is editable
     * in the admin, and the templates read the table rather than this file.
     * Seeding uses updateOrCreate on the key so re-running never overwrites
     * something the client has since reworded.
     *
     * @var list<array{key: string, value: string, type: string, group: string}>
     */
    private const DEFAULTS = [
        // --- identity ---------------------------------------------------
        ['key' => 'site.name', 'value' => 'Photographer', 'type' => 'string', 'group' => 'identity'],
        ['key' => 'site.statement', 'value' => 'Working in available dark. Portraits, series and commissions made slowly, printed carefully, and kept.', 'type' => 'text', 'group' => 'identity'],
        ['key' => 'site.location', 'value' => 'Northumberland, UK', 'type' => 'string', 'group' => 'identity'],
        ['key' => 'site.disciplines', 'value' => 'Portrait — Editorial — Archive', 'type' => 'string', 'group' => 'identity'],

        // --- attribution ------------------------------------------------
        ['key' => 'attribution.name', 'value' => 'A. Marsden', 'type' => 'string', 'group' => 'attribution'],
        ['key' => 'attribution.role', 'value' => 'Photographer & Printer', 'type' => 'string', 'group' => 'attribution'],
        ['key' => 'attribution.city', 'value' => 'Newcastle', 'type' => 'string', 'group' => 'attribution'],

        // --- navigation -------------------------------------------------
        ['key' => 'nav.work', 'value' => 'Work', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.archive', 'value' => 'Archive', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.about', 'value' => 'About', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.journal', 'value' => 'Journal', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.contact', 'value' => 'Contact', 'type' => 'string', 'group' => 'navigation'],

        /*
         * --- the hero's stage rail -------------------------------------
         *
         * Darkroom vocabulary, mapped onto the sections this page actually
         * has. Expose is the work chosen; develop is the one story worked up
         * in full; fix is the archive, where an image is made permanent;
         * print is the closing invitation to commission one.
         */
        ['key' => 'stage.1.label', 'value' => 'Expose', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'stage.2.label', 'value' => 'Develop', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'stage.3.label', 'value' => 'Fix', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'stage.4.label', 'value' => 'Print', 'type' => 'string', 'group' => 'hero'],

        ['key' => 'hero.sequence_label', 'value' => 'Featured Frame', 'type' => 'string', 'group' => 'hero'],

        /*
         * The metadata rail's field names. These read as structure rather than
         * copy, which is exactly why they were nearly left hardcoded — and
         * then "Work" in the rail collided with "Work" in the navigation and
         * made the point for itself. A word a reader sees is copy.
         */
        ['key' => 'rail.year', 'value' => 'Year', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'rail.place', 'value' => 'Place', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'rail.client', 'value' => 'For', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'rail.work', 'value' => 'Series', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'marquee.label', 'value' => 'Selected Clients', 'type' => 'string', 'group' => 'hero'],

        // --- seo ----------------------------------------------------------
        ['key' => 'seo.description', 'value' => 'Photography portfolio, archive and journal.', 'type' => 'text', 'group' => 'seo'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $row) {
            Setting::query()->updateOrCreate(
                ['key' => $row['key']],
                ['value' => $row['value'], 'type' => $row['type'], 'group' => $row['group']],
            );
        }
    }

    /**
     * The keys this seeder guarantees, for tests and for the admin's ordering.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::DEFAULTS, 'key');
    }
}
