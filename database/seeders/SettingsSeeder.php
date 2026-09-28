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
     * Seeding only fills in keys that are missing, so re-running never
     * overwrites something the client has since reworded.
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
        ['key' => 'nav.services', 'value' => 'Services', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.contact', 'value' => 'Contact', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.privacy', 'value' => 'Privacy', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.terms', 'value' => 'Terms', 'type' => 'string', 'group' => 'navigation'],

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

        /*
         * --- the closing call to action ---------------------------------
         *
         * Was hardcoded in the Blade, with a mailto: to example.com. The
         * whole site leads here, so it is the last string that should have
         * been beyond the client's reach.
         */
        ['key' => 'closing.title', 'value' => 'The diary is open for next year.', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'closing.cta', 'value' => 'Start a conversation', 'type' => 'string', 'group' => 'contact'],

        // --- the contact page ----------------------------------------------
        ['key' => 'contact.heading', 'value' => 'Tell me what you are making', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.standfirst', 'value' => 'Commissions, editorial work and prints. There is no form field for the thing that matters, so use the last box for that — what the pictures are for, and who is going to see them.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.response_note', 'value' => 'Every enquiry is read by one person and answered within two working days.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.consent', 'value' => 'I am happy for my details to be kept so this enquiry can be answered.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.consent_detail', 'value' => 'Your message is stored in this studio\'s own admin and emailed to the studio. It is not passed to anyone else, never used for marketing, and deleted on request.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.recipient', 'value' => 'studio@example.com', 'type' => 'string', 'group' => 'contact'],

        /*
         * The brief, which is now the first and largest thing on the form.
         * Both strings are the studio's voice at the moment somebody decides
         * whether to write anything at all, so neither belongs in a template.
         */
        ['key' => 'contact.message_label', 'value' => 'What are you making?', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.message_hint', 'value' => 'The part that gets read first. What the pictures are for, who will see them, and roughly when. Everything underneath is optional.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.submit', 'value' => 'Send this', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.acknowledgement_note', 'value' => 'A copy of this is on its way to', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.confirmation_heading', 'value' => 'It arrived', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.confirmation_body', 'value' => 'Thank you — that is with the studio now. You will get a reply from a person, not an autoresponder, within two working days.', 'type' => 'text', 'group' => 'contact'],

        // --- services ---------------------------------------------------------
        ['key' => 'services.heading', 'value' => 'What the studio does', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.standfirst', 'value' => 'The ways this studio works, and roughly what each one costs. Every job is quoted properly once there is a brief — these are the starting points, not the answer.', 'type' => 'text', 'group' => 'services'],
        ['key' => 'services.price_prefix', 'value' => 'From', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.cta', 'value' => 'Talk about a commission', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.gallery_heading', 'value' => 'From this kind of work', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.empty_line', 'value' => 'Nothing listed here yet.', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.empty_note', 'value' => 'The studio takes commissions whether or not they appear on this page. Write and describe the work.', 'type' => 'text', 'group' => 'services'],
        ['key' => 'services.home_heading', 'value' => 'Working together', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.home_cta', 'value' => 'Everything the studio does', 'type' => 'string', 'group' => 'services'],

        // --- the journal -----------------------------------------------------
        ['key' => 'journal.heading', 'value' => 'Notes from the darkroom', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.standfirst', 'value' => 'Writing about how the work gets made: process, equipment that earns its place, and the occasional argument about printing.', 'type' => 'text', 'group' => 'journal'],
        ['key' => 'journal.empty_note', 'value' => 'Pieces appear here when there is something worth saying.', 'type' => 'text', 'group' => 'journal'],
        ['key' => 'journal.tags_label', 'value' => 'Filed under', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.back_label', 'value' => 'All writing', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.home_heading', 'value' => 'From the journal', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.home_cta', 'value' => 'Everything written', 'type' => 'string', 'group' => 'journal'],

        // --- the about page ------------------------------------------------
        ['key' => 'about.heading', 'value' => 'Available dark', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.biography', 'value' => "I photograph people and places on commission, mostly in the north of England, mostly in whatever light is already there.\n\nI trained as a printer before I trained as a photographer, which is the wrong way round and has been useful ever since: it is hard to overexpose a negative when you have spent three years trying to rescue somebody else's.\n\nWork has appeared in Aperture Quarterly and The Gentlewoman, and in the archives of two harbour trusts who wanted a record rather than a brochure.", 'type' => 'text', 'group' => 'about'],
        ['key' => 'about.approach_heading', 'value' => 'How the work is made', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.approach', 'value' => "Available light, one camera, no assistant unless the job genuinely needs one.\n\nI shoot slowly and edit hard. A commission usually produces between two hundred and four hundred frames and delivers between twelve and thirty, because the edit is the work and handing over a folder of everything is handing over the part nobody wanted.\n\nEverything is printed before it is approved. A picture that only works on a screen is not finished.", 'type' => 'text', 'group' => 'about'],
        ['key' => 'about.clients_heading', 'value' => 'Selected clients', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.studio_label', 'value' => 'Studio', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.cta', 'value' => 'Start a conversation', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.portrait_media_id', 'value' => null, 'type' => 'string', 'group' => 'about'],

        // --- seo ----------------------------------------------------------
        ['key' => 'seo.description', 'value' => 'Photography portfolio, archive and journal.', 'type' => 'text', 'group' => 'seo'],
    ];

    /**
     * Create what is missing; never overwrite what somebody has since edited.
     *
     * updateOrCreate was wrong here and the docblock above was describing an
     * intention rather than the code: re-seeding reset every value to the one
     * in this file, which on a live site means a client's own wording — and
     * their chosen portrait — silently reverting on the next deploy. The type
     * and group are structural rather than editorial, so those are kept in
     * step.
     */
    public function run(): void
    {
        foreach (self::DEFAULTS as $row) {
            $setting = Setting::query()->firstOrNew(['key' => $row['key']]);

            if (! $setting->exists) {
                $setting->value = $row['value'];
            }

            $setting->type = $row['type'];
            $setting->group = $row['group'];
            $setting->save();
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
