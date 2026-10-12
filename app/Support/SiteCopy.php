<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every editable string on the public site: its default, its Arabic, its group.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * ONE REGISTRY, READ BY THREE THINGS, WHICH IS WHY IT EXISTS.
 *
 *   SettingsSeeder   seeds these values into the table on a fresh install
 *   SiteCopy::get()  reads the table and falls back to the value here
 *   the Settings page groups and orders them by where they appear
 *
 * It was two things: the seeder held the defaults, and the Blade templates
 * held a second copy of the same English as the second argument to
 * Settings::get(). That is a duplication that cannot be kept in step — and
 * HomepageTest already forbade it, because a seeded value appearing literally
 * in a template is a string a client cannot change without a deploy. Writing
 * the fallbacks inline made that test fail, correctly.
 *
 * So the English lives here once. A view calls SiteCopy::get('work.see_all')
 * and gets the owner's wording, or the default, or — if somebody deletes the
 * row and the registry entry — the key itself, which is visible and findable
 * rather than a silent gap.
 *
 * PART 2f IS THIS CLASS. "A blank field never renders an empty section" is not
 * a convention anybody has to remember; it is what get() does.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * @var array<int, array{key: string, value: ?string, ar?: ?string, type: string, group: string}>
 */
final class SiteCopy
{
    /**
     * @var list<array{key: string, value: ?string, ar?: ?string, type: string, group: string}>
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
        ['key' => 'services.standfirst', 'value' => 'How this studio works, and roughly what each way costs. Every job is quoted properly once there is a brief — these are the starting points, not the answer.', 'type' => 'text', 'group' => 'services'],
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

        /*
         * ═══════════════════════════════════════════════════════════════════
         * TASK 32, PART 2b/2c — THE STRINGS THAT WERE TYPED INTO TEMPLATES.
         *
         * docs/inventory-public-strings.md counted 97 hardcoded visitor-visible
         * strings in 32 views. The 67 an owner would reasonably want to change
         * are below, with their FORMER HARDCODED TEXT as the seeded value — so
         * seeding reproduces the site exactly as it reads today, and the only
         * thing that changed is who can edit it.
         *
         * Each also carries `ar`, because "both languages everywhere" is a
         * requirement and an owner should not have to translate the furniture
         * of their own site before it works.
         *
         * Groups are by WHERE IT APPEARS, not by key prefix. See SettingGroups.
         * ═══════════════════════════════════════════════════════════════════
         */

        // --- home: the sections below the hero ----------------------------
        ['key' => 'home.introduction_label', 'value' => 'Introduction', 'ar' => 'تعريف', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.introduction_text', 'value' => 'Work made slowly, in series, and shown the same way.', 'ar' => 'أعمال تُصنع على مهل، في متتاليات، وتُعرض بالطريقة نفسها.', 'type' => 'text', 'group' => 'home'],
        ['key' => 'home.selected_heading', 'value' => 'Work, in series', 'ar' => 'أعمال في متتاليات', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.featured_label', 'value' => 'At length', 'ar' => 'بتفصيل', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.featured_cta', 'value' => 'Read the story', 'ar' => 'اقرأ الحكاية', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.archive_heading', 'value' => 'Loose frames', 'ar' => 'إطارات متفرقة', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.archive_note', 'value' => 'Unfiled, most recent first', 'ar' => 'غير مُصنّفة، الأحدث أولًا', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.empty_heading', 'value' => 'Nothing published yet', 'ar' => 'لا يوجد عمل منشور بعد', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.empty_note', 'value' => 'Work appears here once a project is published.', 'ar' => 'ستظهر الأعمال هنا بعد نشر أول مشروع.', 'type' => 'text', 'group' => 'home'],

        // --- the work index -----------------------------------------------
        /*
         * work.heading and archive.heading were ALREADY read from settings and
         * were never seeded — so both pages had been falling back to a literal
         * in the template since they were written, and the Settings page had no
         * row to edit. Found by the test that asserts every key a view asks for
         * is in this registry.
         */
        ['key' => 'work.heading', 'value' => 'Work, in series', 'ar' => 'أعمال في متتاليات', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.filter_all', 'value' => 'All', 'ar' => 'الكل', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.filter_label', 'value' => 'Filter by category', 'ar' => 'التصفية حسب التصنيف', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.empty_filtered', 'value' => 'Nothing published under :category yet.', 'ar' => 'لا يوجد عمل منشور تحت :category بعد.', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.empty', 'value' => 'Nothing published yet.', 'ar' => 'لا يوجد عمل منشور بعد.', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.empty_note', 'value' => 'Work appears here as it is finished and printed.', 'ar' => 'تظهر الأعمال هنا كلما اكتمل عمل وطُبع.', 'type' => 'text', 'group' => 'work'],
        ['key' => 'work.see_all', 'value' => 'See all work', 'ar' => 'عرض كل الأعمال', 'type' => 'string', 'group' => 'work'],

        // --- a project page ------------------------------------------------
        ['key' => 'project.year_label', 'value' => 'Year', 'ar' => 'السنة', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.client_label', 'value' => 'Client', 'ar' => 'العميل', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.location_label', 'value' => 'Location', 'ar' => 'المكان', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.categories_label', 'value' => 'Categories', 'ar' => 'التصنيفات', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.credits_label', 'value' => 'Credits', 'ar' => 'فريق العمل', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.preview_warning', 'value' => 'Preview of an unpublished project. This link expires and is not indexed.', 'ar' => 'معاينة لمشروع غير منشور. ينتهي هذا الرابط ولا يُفهرَس.', 'type' => 'text', 'group' => 'project'],

        // --- the archive ----------------------------------------------------
        ['key' => 'archive.heading', 'value' => 'Every frame that made it out', 'ar' => 'كل إطار وصل إلى النور', 'type' => 'string', 'group' => 'archive'],
        /*
         * "roll 2 of 7" — one sentence, not three fragments.
         *
         * Seeded as a whole line with placeholders rather than as the word
         * "roll" plus a hardcoded " of ": Arabic puts the number and the
         * counter in a different order, and an owner who wants "sheet 2/7"
         * should be able to write exactly that.
         */
        ['key' => 'archive.roll_position', 'value' => 'roll :current of :total', 'ar' => 'لفّة :current من :total', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.series_label', 'value' => 'Series', 'ar' => 'المتتالية', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.year_label', 'value' => 'Year', 'ar' => 'السنة', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.filter_all', 'value' => 'All', 'ar' => 'الكل', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.filter_label', 'value' => 'Filter the archive', 'ar' => 'تصفية الأرشيف', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.empty', 'value' => 'No frames under that filter.', 'ar' => 'لا توجد إطارات تحت هذه التصفية.', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.empty_note', 'value' => 'The archive only holds pictures that reached a published page.', 'ar' => 'لا يضم الأرشيف إلا الصور التي وصلت إلى صفحة منشورة.', 'type' => 'text', 'group' => 'archive'],
        ['key' => 'archive.see_sheet', 'value' => 'See the whole sheet', 'ar' => 'عرض اللوح كاملًا', 'type' => 'string', 'group' => 'archive'],

        // --- the footer, and the social links -------------------------------
        /*
         * THE LABEL *AND* THE URL, and both may be empty.
         *
         * Instagram and Bluesky were typed into layouts/public.blade.php, which
         * decided which two networks this studio uses for ever. A photographer
         * who leaves Instagram should not need a developer, and one who uses
         * neither should get no row of links rather than two dead ones — so a
         * blank URL removes the link entirely rather than falling back.
         */
        ['key' => 'footer.social_1_label', 'value' => 'Instagram', 'ar' => 'إنستغرام', 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.social_1_url', 'value' => 'https://instagram.com/', 'ar' => null, 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.social_2_label', 'value' => 'Bluesky', 'ar' => 'بلوسكاي', 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.social_2_url', 'value' => 'https://bsky.app/', 'ar' => null, 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.social_3_label', 'value' => null, 'ar' => null, 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.social_3_url', 'value' => null, 'ar' => null, 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.copyright', 'value' => 'All rights reserved.', 'ar' => 'جميع الحقوق محفوظة.', 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer.nav_label', 'value' => 'Primary', 'ar' => 'الرئيسية', 'type' => 'string', 'group' => 'footer'],

        // --- shared across pages --------------------------------------------
        ['key' => 'shared.theme_label', 'value' => 'Colour scheme', 'ar' => 'نمط الألوان', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.read_time_unit', 'value' => 'min', 'ar' => 'دقيقة', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.next_label', 'value' => 'Where to go next', 'ar' => 'إلى أين بعد ذلك', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.last_updated', 'value' => 'Last updated', 'ar' => 'آخر تحديث', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.pagination_previous', 'value' => 'Previous', 'ar' => 'السابق', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.pagination_next', 'value' => 'Next', 'ar' => 'التالي', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.pagination_position', 'value' => 'Page :current of :total', 'ar' => 'صفحة :current من :total', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.gallery_label', 'value' => 'Image gallery, scrollable', 'ar' => 'معرض صور قابل للتمرير', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.video_cta', 'value' => 'Watch the video', 'ar' => 'مشاهدة المقطع', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.embed_cta', 'value' => 'Open on', 'ar' => 'افتح على', 'type' => 'string', 'group' => 'shared'],


        /*
         * The paginator's own words.
         *
         * `Str::plural($unit)` was applying ENGLISH pluralisation rules to a
         * hardcoded English noun — "frame"/"frames", "series"/"series" — on a
         * page that may be Arabic. Arabic does not form plurals that way and
         * the noun was not translatable in the first place.
         *
         * So the caller names a unit KEY and the owner writes both forms. One
         * Arabic plural form rather than the dual-and-plural the grammar has:
         * "٣ إطارات" reads correctly for every count a paginated archive will
         * ever show, and a UI that switched noun form at exactly two would be
         * more surprising than consistent.
         */
        ['key' => 'shared.unit_frame', 'value' => ':count frames', 'ar' => ':count إطارات', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_frame_one', 'value' => ':count frame', 'ar' => 'إطار واحد', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_series', 'value' => ':count series', 'ar' => ':count متتاليات', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_series_one', 'value' => ':count series', 'ar' => 'متتالية واحدة', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.pagination_label', 'value' => 'Pagination', 'ar' => 'التصفّح', 'type' => 'string', 'group' => 'shared'],

        // --- the error pages -------------------------------------------------
        ['key' => 'errors.403_heading', 'value' => 'Not for you', 'ar' => 'ليست لك', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.403_body', 'value' => 'This page is private, or the link you followed has since been locked.', 'ar' => 'هذه الصفحة خاصة، أو أن الرابط الذي اتبعته أُغلق منذ ذلك الحين.', 'type' => 'text', 'group' => 'errors'],
        ['key' => 'errors.404_heading', 'value' => 'Nothing here', 'ar' => 'لا شيء هنا', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.404_body', 'value' => 'The page you asked for has moved, been renamed, or never existed.', 'ar' => 'الصفحة التي طلبتها نُقلت أو أُعيدت تسميتها أو لم تكن موجودة أصلًا.', 'type' => 'text', 'group' => 'errors'],
        ['key' => 'errors.419_heading', 'value' => 'The page expired', 'ar' => 'انتهت صلاحية الصفحة', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.419_body', 'value' => 'You were idle long enough that the form went stale. Go back and send it again.', 'ar' => 'مرّ وقت كافٍ من السكون حتى فقد النموذج صلاحيته. ارجع وأعد الإرسال.', 'type' => 'text', 'group' => 'errors'],
        ['key' => 'errors.429_heading', 'value' => 'Too many requests', 'ar' => 'طلبات كثيرة جدًا', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.429_body', 'value' => 'Something asked for a great deal at once. Wait a moment and try again.', 'ar' => 'طلب شيءٌ قدرًا كبيرًا في وقت واحد. انتظر لحظة ثم أعد المحاولة.', 'type' => 'text', 'group' => 'errors'],
        ['key' => 'errors.500_heading', 'value' => 'Something broke', 'ar' => 'حدث خطأ', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.500_body', 'value' => 'An error on our side stopped this page from loading. It has been logged.', 'ar' => 'خطأ من جهتنا منع تحميل هذه الصفحة. وقد سُجّل الخطأ.', 'type' => 'text', 'group' => 'errors'],
        ['key' => 'errors.503_heading', 'value' => 'Back shortly', 'ar' => 'نعود قريبًا', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.503_body', 'value' => 'The site is down for brief maintenance. Nothing is lost; try again in a few minutes.', 'ar' => 'الموقع متوقف لصيانة قصيرة. لم يُفقد شيء؛ أعد المحاولة بعد دقائق.', 'type' => 'text', 'group' => 'errors'],
        ['key' => 'errors.return_home', 'value' => 'Return home', 'ar' => 'العودة إلى الرئيسية', 'type' => 'string', 'group' => 'errors'],
        ['key' => 'errors.code_prefix', 'value' => 'Error', 'ar' => 'خطأ', 'type' => 'string', 'group' => 'errors'],

        // --- the contact form's markers ---------------------------------------
        ['key' => 'contact.required_marker', 'value' => 'required', 'ar' => 'مطلوب', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.optional_marker', 'value' => 'optional', 'ar' => 'اختياري', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.sent_at_label', 'value' => 'Sent', 'ar' => 'أُرسلت', 'type' => 'string', 'group' => 'contact'],


        /*
         * --- the contact form's own fields -----------------------------------
         *
         * These were a PHP array inside contact/show.blade.php: ten labels,
         * four hints and two legends, typed into the template. The scanner that
         * produced the inventory missed them because they sit inside @php, which
         * is a reminder that a count is a floor and not a ceiling.
         *
         * They are the most-read copy on the site after the hero — this is the
         * form a client fills in to hire the studio — and the owner could not
         * change a word of it.
         */
        ['key' => 'contact.legend_about', 'value' => 'About you', 'ar' => 'عنك', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.legend_job', 'value' => 'If you know it yet', 'ar' => 'إن كنت تعرفه بعد', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_name', 'value' => 'Your name', 'ar' => 'اسمك', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_email', 'value' => 'Email', 'ar' => 'البريد الإلكتروني', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_phone', 'value' => 'Phone', 'ar' => 'الهاتف', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_phone_hint', 'value' => 'Only if you would rather talk than type.', 'ar' => 'فقط إذا كنت تفضّل الحديث على الكتابة.', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_company', 'value' => 'Company or publication', 'ar' => 'الجهة أو المنشور', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_project_type', 'value' => 'Kind of work', 'ar' => 'نوع العمل', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_project_type_hint', 'value' => 'Portrait, editorial, archive, something else.', 'ar' => 'بورتريه، تحريري، أرشيف، أو شيء آخر.', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_date', 'value' => 'Date', 'ar' => 'التاريخ', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_date_hint', 'value' => 'In whatever form you have it — “14 May”, “late spring”, “the week of the 3rd”.', 'ar' => 'بأي صيغة لديك — «١٤ مايو»، «أواخر الربيع»، «أسبوع الثالث».', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_location', 'value' => 'Where', 'ar' => 'المكان', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_budget', 'value' => 'Budget', 'ar' => 'الميزانية', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_budget_hint', 'value' => 'A range is fine. It decides what is possible, not whether you get a reply.', 'ar' => 'النطاق التقريبي يكفي. هو يحدّد ما هو ممكن، لا ما إذا كنت ستتلقّى ردًا.', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.honeypot_label', 'value' => 'Do not fill this in', 'ar' => 'لا تملأ هذا الحقل', 'type' => 'string', 'group' => 'contact_form'],

        // --- the journal -------------------------------------------------------
        ['key' => 'journal.filter_all', 'value' => 'All', 'ar' => 'الكل', 'type' => 'string', 'group' => 'journal'],

        /*
         * The unfiltered empty line. `journal.empty_note` already existed and
         * is the SECOND line of that block, so reusing it for the first printed
         * the same sentence twice — which is the mistake the two-factor page
         * made in Task 30b, in a different view.
         */
        ['key' => 'journal.empty', 'value' => 'Nothing written here yet.', 'ar' => 'لا توجد كتابات هنا بعد.', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.filter_label', 'value' => 'Filter by subject', 'ar' => 'التصفية حسب الموضوع', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.empty_filtered', 'value' => 'Nothing written under :category yet.', 'ar' => 'لا توجد كتابات في :category بعد.', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.read_everything', 'value' => 'Read everything', 'ar' => 'اقرأ كل شيء', 'type' => 'string', 'group' => 'journal'],

        // --- services -----------------------------------------------------------
        ['key' => 'services.count', 'value' => ':count ways of working', 'ar' => ':count طرق للعمل', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.count_one', 'value' => 'One way of working', 'ar' => 'طريقة واحدة للعمل', 'type' => 'string', 'group' => 'services'],

        /*
         * --- search & sharing: the two site-wide IMAGES (2c) -------------------
         *
         * Both chosen from the media library by id, never a path typed into a
         * field: a path is a string that can be wrong in a way nothing notices
         * until a share card renders a broken image.
         *
         * The favicon is not merely unset — public/favicon.ico is Laravel's
         * 0-byte placeholder and no <link rel="icon"> existed in any layout, so
         * this site had no favicon at all.
         */
        ['key' => 'seo.og_image_media_id', 'value' => null, 'ar' => null, 'type' => 'string', 'group' => 'seo'],
        ['key' => 'seo.favicon_media_id', 'value' => null, 'ar' => null, 'type' => 'string', 'group' => 'seo'],
    ];

    /** @var array<string, array{key: string, value: ?string, ar?: ?string, type: string, group: string}>|null */
    private static ?array $byKey = null;

    /**
     * The owner's wording, or the built-in default.
     *
     * The key itself is the last resort rather than an empty string: a page
     * reading `work.see_all` is obviously wrong and searchable, where a page
     * with a missing link is wrong in a way nobody reports.
     */
    public static function get(string $key): string
    {
        return Settings::get($key, self::default($key) ?? $key) ?? $key;
    }

    /**
     * A string with :placeholders filled in.
     *
     * Same convention as Laravel's translator, so an owner editing
     * "Nothing published under :category yet." in the panel is editing
     * something they can reason about — and one who deletes the placeholder
     * gets a sentence without it rather than a crash.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function line(string $key, array $replace = []): string
    {
        $text = self::get($key);

        foreach ($replace as $placeholder => $value) {
            $text = str_replace(':'.$placeholder, (string) $value, $text);
        }

        return $text;
    }

    /** The built-in default for a key, if this registry knows it. */
    public static function default(string $key): ?string
    {
        self::$byKey ??= array_column(self::DEFAULTS, null, 'key');

        return self::$byKey[$key]['value'] ?? null;
    }

    /**
     * Every row, for the seeder.
     *
     * @return list<array{key: string, value: ?string, ar?: ?string, type: string, group: string}>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::DEFAULTS, 'key');
    }
}
