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
        /*
         * The SEEDED value is a placeholder common noun, so it gets one in
         * each language. A real studio's name is a proper noun and the owner
         * will replace both — but a fresh install should not be half-English.
         */
        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE STUDIO'S REAL NAME, AND WHY IT IS STILL A DEFAULT.
         *
         * "Photographer" was the placeholder a site with no client yet is
         * built on. The studio is Anas Fareed.
         *
         * This row is the DEFAULT, not the value: SettingsSeeder creates a
         * setting and never updates one, so a studio that has since renamed
         * itself keeps its own name through every deploy (see
         * Concerns\ProtectsEditedContent). Changing a name on a site that is
         * already running is therefore data — a write through Settings, which
         * is what the Settings screen does — and this line is what a fresh
         * install starts from.
         *
         * NOTHING ELSE ABOUT THE SHAPE OF THIS STRING IS ASSUMED. The hero
         * sizes the wordmark from its character count, so a shorter name
         * grows and a longer one shrinks; "Anas Fareed" contains a SPACE,
         * which the single-line wordmark had never been rendered with until
         * Task 34d C2 measured it at four widths in both languages.
         * ═══════════════════════════════════════════════════════════════════
         */
        ['key' => 'site.name', 'value' => 'Anas Fareed', 'ar' => 'أنس فريد', 'type' => 'string', 'group' => 'identity'],
        ['key' => 'site.statement', 'value' => 'Commercial photography for products, spaces and campaigns — planned around the brief, lit with intent and delivered ready to use.', 'ar' => 'تصوير تجاري للمنتجات والمساحات والحملات الإعلانية — يُخطَّط له حول الموجز، ويُضاء بقصد، ويُسلَّم جاهزًا للاستخدام.', 'type' => 'text', 'group' => 'identity'],
        ['key' => 'site.location', 'value' => null, 'ar' => null, 'type' => 'string', 'group' => 'identity'],
        ['key' => 'site.disciplines', 'value' => 'Product — Advertising — Interiors & Architecture', 'ar' => 'منتجات — إعلان — تصميم داخلي وعمارة', 'type' => 'string', 'group' => 'identity'],

        // --- attribution ------------------------------------------------
        /*
         * The name on the attribution line, in the journal's byline and in
         * the structured data's founder — the same person as site.name, and
         * it had a different placeholder in it.
         */
        ['key' => 'attribution.name', 'value' => 'Anas Fareed', 'ar' => 'أنس فريد', 'type' => 'string', 'group' => 'attribution'],
        ['key' => 'attribution.role', 'value' => 'Commercial Photographer', 'ar' => 'مصوّر تجاري', 'type' => 'string', 'group' => 'attribution'],
        ['key' => 'attribution.city', 'value' => null, 'ar' => null, 'type' => 'string', 'group' => 'attribution'],

        // --- navigation -------------------------------------------------
        ['key' => 'nav.work', 'value' => 'Work', 'ar' => 'الأعمال', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.archive', 'value' => 'Archive', 'ar' => 'الأرشيف', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.about', 'value' => 'About', 'ar' => 'نبذة', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.journal', 'value' => 'Journal', 'ar' => 'المجلة', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.services', 'value' => 'Services', 'ar' => 'الخدمات', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.contact', 'value' => 'Contact', 'ar' => 'تواصل', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.privacy', 'value' => 'Privacy', 'ar' => 'الخصوصية', 'type' => 'string', 'group' => 'navigation'],
        ['key' => 'nav.terms', 'value' => 'Terms', 'ar' => 'الشروط', 'type' => 'string', 'group' => 'navigation'],

        /*
         * --- the hero's stage rail -------------------------------------
         *
         * Darkroom vocabulary, mapped onto the sections this page actually
         * has. Expose is the work chosen; develop is the one story worked up
         * in full; fix is the archive, where an image is made permanent;
         * print is the closing invitation to commission one.
         */
        ['key' => 'stage.1.label', 'value' => 'Brief', 'ar' => 'الموجز', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'stage.2.label', 'value' => 'Plan', 'ar' => 'التخطيط', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'stage.3.label', 'value' => 'Shoot', 'ar' => 'التصوير', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'stage.4.label', 'value' => 'Deliver', 'ar' => 'التسليم', 'type' => 'string', 'group' => 'hero'],

        ['key' => 'hero.sequence_label', 'value' => 'Featured project', 'ar' => 'مشروع مختار', 'type' => 'string', 'group' => 'hero'],

        /*
         * The metadata rail's field names. These read as structure rather than
         * copy, which is exactly why they were nearly left hardcoded — and
         * then "Work" in the rail collided with "Work" in the navigation and
         * made the point for itself. A word a reader sees is copy.
         */
        ['key' => 'rail.year', 'value' => 'Year', 'ar' => 'السنة', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'rail.place', 'value' => 'Location', 'ar' => 'الموقع', 'type' => 'string', 'group' => 'hero'],
        // Task 41 7.4: «لصالح», not «لـ». The prefix on its own rendered as a
        // bare letter on the rail — a label with no word in it.
        ['key' => 'rail.client', 'value' => 'Client', 'ar' => 'العميل', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'rail.work', 'value' => 'Project', 'ar' => 'المشروع', 'type' => 'string', 'group' => 'hero'],
        // Task 38 E1 — the spec table's labels. Values are the picture's own EXIF.
        ['key' => 'spec.camera', 'value' => 'Camera', 'ar' => 'الكاميرا', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'spec.lens', 'value' => 'Lens', 'ar' => 'العدسة', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'spec.focal_length', 'value' => 'Focal length', 'ar' => 'البعد البؤري', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'spec.aperture', 'value' => 'Aperture', 'ar' => 'فتحة العدسة', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'spec.shutter', 'value' => 'Shutter', 'ar' => 'سرعة الغالق', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'spec.iso', 'value' => 'ISO', 'ar' => 'الحساسية', 'type' => 'string', 'group' => 'hero'],
        ['key' => 'marquee.label', 'value' => 'Selected Clients', 'ar' => 'عملاء مختارون', 'type' => 'string', 'group' => 'hero'],

        /*
         * --- the closing call to action ---------------------------------
         *
         * Was hardcoded in the Blade, with a mailto: to example.com. The
         * whole site leads here, so it is the last string that should have
         * been beyond the client's reach.
         */
        /*
         * Task 34 D2. The heading over the testimonial strip, which sits
         * immediately before the closing invitation — so it belongs to the
         * same group an owner edits when they are thinking about that part
         * of the page.
         */
        ['key' => 'testimonials.heading', 'value' => 'What clients say', 'ar' => 'ما يقوله العملاء', 'type' => 'string', 'group' => 'contact'],

        ['key' => 'closing.title', 'value' => 'Planning a shoot? Send the brief.', 'ar' => 'تخطّط لجلسة تصوير؟ أرسل الموجز.', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'closing.cta', 'value' => 'Request a quote', 'ar' => 'اطلب عرض سعر', 'type' => 'string', 'group' => 'contact'],

        // --- the contact page ----------------------------------------------
        ['key' => 'contact.heading', 'value' => 'Book a shoot or request a quote', 'ar' => 'احجز جلسة تصوير أو اطلب عرض سعر', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.standfirst', 'value' => 'Tell us what you need photographed and where the images will be used — a product range, a campaign, a space. We reply with the questions that matter, a plan and a quotation.', 'ar' => 'أخبرنا بما تحتاج إلى تصويره وأين ستُستخدم الصور — مجموعة منتجات أو حملة أو مساحة. نردّ عليك بالأسئلة المهمة وخطة عمل وعرض سعر.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.response_note', 'value' => 'Every enquiry is read in full and answered by a person.', 'ar' => 'نقرأ كل طلب كاملًا، ويردّ عليه شخص لا نظام آلي.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.consent', 'value' => 'I am happy for my details to be kept so this enquiry can be answered.', 'ar' => 'أوافق على حفظ بياناتي ليتمكّن الاستوديو من الرد على هذا الطلب.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.consent_detail', 'value' => 'Your message is stored in this studio\'s own admin and emailed to the studio. It is not passed to anyone else, never used for marketing, and deleted on request.', 'ar' => 'تُحفَظ رسالتك في لوحة الاستوديو نفسها وتُرسَل إليه بالبريد. لا تُعطى لأي جهة أخرى، ولا تُستخدم للتسويق أبدًا، وتُحذَف عند الطلب.', 'type' => 'text', 'group' => 'contact'],
        // Empty until the owner sets it (Site settings → Contact). It shipped as
        // studio@example.com, which a fresh install then published and mailed.
        ['key' => 'contact.recipient', 'value' => null, 'type' => 'string', 'group' => 'contact'],

        /*
         * The brief, which is now the first and largest thing on the form.
         * Both strings are the studio's voice at the moment somebody decides
         * whether to write anything at all, so neither belongs in a template.
         */
        ['key' => 'contact.message_label', 'value' => 'What do you need photographed?', 'ar' => 'ما الذي تحتاج إلى تصويره؟', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.message_hint', 'value' => 'Products, a space or a campaign — roughly how many items or locations, where the images will appear and when you need them. Everything below is optional.', 'ar' => 'منتجات أو مساحة أو حملة — كم قطعة أو موقعًا تقريبًا، وأين ستظهر الصور، ومتى تحتاجها. كل ما بعد ذلك اختياري.', 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact.submit', 'value' => 'Send enquiry', 'ar' => 'أرسل الطلب', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.acknowledgement_note', 'value' => 'A copy of this is on its way to', 'ar' => 'نسخة من هذا في طريقها إلى', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.confirmation_heading', 'value' => 'Enquiry received', 'ar' => 'وصل طلبك', 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact.confirmation_body', 'value' => 'Thank you — your enquiry is with the studio. You will hear back from a person, not an autoresponder.', 'ar' => 'شكرًا لك — طلبك الآن لدى الاستوديو. سيردّ عليك شخص، لا ردّ آلي.', 'type' => 'text', 'group' => 'contact'],

        // --- services ---------------------------------------------------------
        ['key' => 'services.heading', 'value' => 'Photography services', 'ar' => 'خدمات التصوير', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.standfirst', 'value' => 'How the studio works with brands and businesses. Every shoot is quoted from the brief — the number of products or locations, the styling and retouching, and where the images will be used.', 'ar' => 'كيف يعمل الاستوديو مع العلامات التجارية والشركات. تُسعَّر كل جلسة بناءً على الموجز — عدد المنتجات أو المواقع، والتنسيق والمعالجة، وأين ستُستخدم الصور.', 'type' => 'text', 'group' => 'services'],
        ['key' => 'services.price_prefix', 'value' => 'From', 'ar' => 'يبدأ من', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.cta', 'value' => 'Request a quote', 'ar' => 'اطلب عرض سعر', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.gallery_heading', 'value' => 'Examples of this work', 'ar' => 'نماذج من هذا العمل', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.empty_line', 'value' => 'Nothing listed here yet.', 'ar' => 'لا شيء مدرج هنا بعد.', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.empty_note', 'value' => 'The studio takes on commercial work whether or not it is listed here. Send a brief and describe the job.', 'ar' => 'يقبل الاستوديو الأعمال التجارية سواء ظهرت في هذه الصفحة أو لا. أرسل الموجز واشرح المطلوب.', 'type' => 'text', 'group' => 'services'],
        ['key' => 'services.home_heading', 'value' => 'What we photograph', 'ar' => 'ما نصوّره', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.home_cta', 'value' => 'All services', 'ar' => 'كل الخدمات', 'type' => 'string', 'group' => 'services'],

        // --- the journal -----------------------------------------------------
        ['key' => 'journal.heading', 'value' => 'Notes from the studio', 'ar' => 'ملاحظات من الاستوديو', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.standfirst', 'value' => 'Writing on how commercial shoots are planned and made: lighting, sets, products, spaces and the decisions behind them.', 'ar' => 'كتابات عن كيفية التخطيط لجلسات التصوير التجاري وتنفيذها: الإضاءة والديكورات والمنتجات والمساحات والقرارات التي تقف خلفها.', 'type' => 'text', 'group' => 'journal'],
        ['key' => 'journal.empty_note', 'value' => 'Pieces appear here when there is something worth saying.', 'ar' => 'تظهر المقالات هنا عندما يكون هناك ما يستحق أن يُقال.', 'type' => 'text', 'group' => 'journal'],
        ['key' => 'journal.tags_label', 'value' => 'Filed under', 'ar' => 'مُصنَّف تحت', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.back_label', 'value' => 'All writing', 'ar' => 'كل الكتابات', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.home_heading', 'value' => 'From the journal', 'ar' => 'من المجلة', 'type' => 'string', 'group' => 'journal'],
        ['key' => 'journal.home_cta', 'value' => 'Everything written', 'ar' => 'كل ما كُتب', 'type' => 'string', 'group' => 'journal'],

        // --- the about page ------------------------------------------------
        ['key' => 'about.heading', 'value' => 'About the studio', 'ar' => 'عن الاستوديو', 'type' => 'string', 'group' => 'about'],
        /*
     * ───────────────────────────────────────────────────────────────────────
     * 34e — THE ARABIC PARAGRAPHS WERE SINGLE-QUOTED.
     *
     * The English halves of about.biography and about.approach are written in
     * DOUBLE quotes, where \n is a newline. The Arabic halves were written in
     * single quotes, where it is a backslash and an n — so the Arabic about
     * page rendered "غالبًا.\n\nتدرّبت على الطباعة" with the escape visible in
     * the middle of the sentence, on the page that introduces the studio.
     *
     * Found by screenshotting that page in Arabic after the webfonts landed,
     * which is the first time anybody had looked at it closely enough to read
     * the words rather than check the direction.
     * ───────────────────────────────────────────────────────────────────────
     */
        ['key' => 'about.biography', 'value' => "Anas Fareed is a commercial photographer working with brands, manufacturers, retailers, agencies and design studios.\n\nThe work covers products, advertising campaigns, interiors and architecture, photographed in the studio or on location. Several studio spaces are used for product work, so the set can be matched to the job — a seamless backdrop for e-commerce, a tabletop for details, a built set for a campaign.\n\nEvery shoot starts from where the images will be used. A product photographed for an online store, a billboard and a printed catalogue needs three different plans, and that plan is agreed before the camera comes out.", 'ar' => "أنس فريد مصوّر تجاري يعمل مع العلامات التجارية والمصنّعين وتجّار التجزئة والوكالات واستوديوهات التصميم.\n\nيشمل عمله تصوير المنتجات والحملات الإعلانية والتصميم الداخلي والعمارة، في الاستوديو أو في الموقع. وتُستخدم عدة مساحات استوديو لتصوير المنتجات، فيُختار الإعداد المناسب لكل عمل — خلفية متصلة للمتاجر الإلكترونية، أو طاولة تصوير للتفاصيل، أو ديكور مبني لحملة إعلانية.\n\nتبدأ كل جلسة من المكان الذي ستُستخدم فيه الصور. فالمنتج الذي يُصوَّر لمتجر إلكتروني وللوحة إعلانية ولكتالوج مطبوع يحتاج إلى ثلاث خطط مختلفة، ويُتفق على الخطة قبل أن تُرفع الكاميرا.", 'type' => 'text', 'group' => 'about'],
        ['key' => 'about.approach_heading', 'value' => 'How a shoot runs', 'ar' => 'كيف تسير جلسة التصوير', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.approach', 'value' => "Brief. We start with what the images are for, where they will appear and what they need to show.\n\nPlan. A shot list, studio or location, lighting, styling and a schedule — agreed with you before the day.\n\nShoot. In the studio or on location, with frames reviewed as we work so nothing is left to guesswork.\n\nDeliver. A selected, retouched set, sized and exported for every place it will be used.", 'ar' => "الموجز. نبدأ بالغرض من الصور، وأين ستظهر، وما الذي يجب أن تُظهره.\n\nالتخطيط. قائمة اللقطات، والاستوديو أو الموقع، والإضاءة، والتنسيق، والجدول الزمني — نتفق عليها معك قبل يوم التصوير.\n\nالتصوير. في الاستوديو أو في الموقع، مع مراجعة اللقطات أثناء العمل فلا يُترك شيء للتخمين.\n\nالتسليم. مجموعة مختارة ومعالجة، بالمقاسات والصيغ المناسبة لكل مكان ستُستخدم فيه.", 'type' => 'text', 'group' => 'about'],
        ['key' => 'about.clients_heading', 'value' => 'Selected clients', 'ar' => 'عملاء مختارون', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.studio_label', 'value' => 'Studio', 'ar' => 'الاستوديو', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.cta', 'value' => 'Request a quote', 'ar' => 'اطلب عرض سعر', 'type' => 'string', 'group' => 'about'],
        ['key' => 'about.portrait_media_id', 'value' => null, 'type' => 'string', 'group' => 'about'],

        // --- seo ----------------------------------------------------------
        ['key' => 'seo.description', 'value' => 'Commercial photography by Anas Fareed: product, studio, advertising, interiors and architecture photography for brands and businesses.', 'ar' => 'تصوير تجاري مع أنس فريد: تصوير المنتجات والاستوديو والإعلانات والتصميم الداخلي والعمارة للعلامات التجارية والشركات.', 'type' => 'text', 'group' => 'seo'],

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
        ['key' => 'home.introduction_label', 'value' => 'The studio', 'ar' => 'الاستوديو', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.introduction_text', 'value' => 'From product launches and advertising campaigns to interiors and architectural spaces, we create considered imagery that helps brands communicate clearly and confidently.', 'ar' => 'من إطلاق المنتجات والحملات الإعلانية إلى التصاميم الداخلية والمساحات المعمارية، نصنع صورًا مدروسة تساعد العلامات التجارية على التواصل بوضوح وثقة.', 'type' => 'text', 'group' => 'home'],
        // Task 38 B4 — the scrubbed statement's accent rule, per locale. "last"
        // sets the sentence's final word in the accent; "none" turns it off.
        // Not empty for off: an emptied setting falls back to English and
        // then to this default, so an empty field could never mean "off".
        // Not copy: a switch the owner holds.
        ['key' => 'home.statement_accent', 'value' => 'last', 'ar' => 'last', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.selected_heading', 'value' => 'Selected projects', 'ar' => 'مشاريع مختارة', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.featured_label', 'value' => 'Project in detail', 'ar' => 'مشروع بالتفصيل', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.featured_cta', 'value' => 'See the project', 'ar' => 'شاهد المشروع', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.archive_heading', 'value' => 'Recent images', 'ar' => 'صور حديثة', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.archive_note', 'value' => 'Most recent first', 'ar' => 'الأحدث أولًا', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.empty_heading', 'value' => 'Nothing published yet', 'ar' => 'لا يوجد عمل منشور بعد', 'type' => 'string', 'group' => 'home'],
        ['key' => 'home.empty_note', 'value' => 'Projects appear here once they are published.', 'ar' => 'تظهر المشاريع هنا بعد نشرها.', 'type' => 'text', 'group' => 'home'],

        // --- the work index -----------------------------------------------
        /*
         * work.heading and archive.heading were ALREADY read from settings and
         * were never seeded — so both pages had been falling back to a literal
         * in the template since they were written, and the Settings page had no
         * row to edit. Found by the test that asserts every key a view asks for
         * is in this registry.
         */
        ['key' => 'work.heading', 'value' => 'Portfolio', 'ar' => 'معرض الأعمال', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.filter_all', 'value' => 'All', 'ar' => 'الكل', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.filter_label', 'value' => 'Filter by category', 'ar' => 'التصفية حسب التصنيف', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.empty_filtered', 'value' => 'Nothing published under :category yet.', 'ar' => 'لا يوجد عمل منشور تحت :category بعد.', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.empty', 'value' => 'Nothing published yet.', 'ar' => 'لا يوجد عمل منشور بعد.', 'type' => 'string', 'group' => 'work'],
        ['key' => 'work.empty_note', 'value' => 'Projects appear here as they are published.', 'ar' => 'تظهر المشاريع هنا عند نشرها.', 'type' => 'text', 'group' => 'work'],
        ['key' => 'work.see_all', 'value' => 'See the full portfolio', 'ar' => 'عرض معرض الأعمال كاملًا', 'type' => 'string', 'group' => 'work'],

        // --- a project page ------------------------------------------------
        ['key' => 'project.year_label', 'value' => 'Year', 'ar' => 'السنة', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.client_label', 'value' => 'Client', 'ar' => 'العميل', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.location_label', 'value' => 'Location', 'ar' => 'المكان', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.categories_label', 'value' => 'Categories', 'ar' => 'التصنيفات', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.credits_label', 'value' => 'Credits', 'ar' => 'فريق العمل', 'type' => 'string', 'group' => 'project'],
        ['key' => 'project.preview_warning', 'value' => 'Preview of an unpublished project. This link expires and is not indexed.', 'ar' => 'معاينة لمشروع غير منشور. ينتهي هذا الرابط ولا يُفهرَس.', 'type' => 'text', 'group' => 'project'],

        // --- the archive ----------------------------------------------------
        ['key' => 'archive.heading', 'value' => 'Image archive', 'ar' => 'أرشيف الصور', 'type' => 'string', 'group' => 'archive'],
        /*
         * "roll 2 of 7" — one sentence, not three fragments.
         *
         * Seeded as a whole line with placeholders rather than as the word
         * "roll" plus a hardcoded " of ": Arabic puts the number and the
         * counter in a different order, and an owner who wants "sheet 2/7"
         * should be able to write exactly that.
         */
        ['key' => 'archive.roll_position', 'value' => 'sheet :current of :total', 'ar' => 'لوح :current من :total', 'type' => 'string', 'group' => 'archive'],
        ['key' => 'archive.series_label', 'value' => 'Project', 'ar' => 'المشروع', 'type' => 'string', 'group' => 'archive'],
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
        ['key' => 'shared.unit_series', 'value' => ':count projects', 'ar' => ':count مشاريع', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_series_one', 'value' => ':count project', 'ar' => 'مشروع واحد', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_piece', 'value' => ':count pieces', 'ar' => ':count مقالات', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_piece_one', 'value' => ':count piece', 'ar' => 'مقالة واحدة', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_set', 'value' => ':count sets', 'ar' => ':count مجموعات', 'type' => 'string', 'group' => 'shared'],
        ['key' => 'shared.unit_set_one', 'value' => ':count set', 'ar' => 'مجموعة واحدة', 'type' => 'string', 'group' => 'shared'],
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
        ['key' => 'contact.legend_job', 'value' => 'About the shoot', 'ar' => 'عن جلسة التصوير', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_name', 'value' => 'Your name', 'ar' => 'اسمك', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_email', 'value' => 'Email', 'ar' => 'البريد الإلكتروني', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_phone', 'value' => 'Phone', 'ar' => 'الهاتف', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_phone_hint', 'value' => 'Only if you would rather talk than type.', 'ar' => 'فقط إذا كنت تفضّل الحديث على الكتابة.', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_company', 'value' => 'Company or brand', 'ar' => 'الشركة أو العلامة التجارية', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_project_type', 'value' => 'Type of shoot', 'ar' => 'نوع التصوير', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_project_type_hint', 'value' => 'Product, studio, advertising, interiors and architecture, location — or something else.', 'ar' => 'منتجات، استوديو، إعلان، تصميم داخلي وعمارة، مواقع — أو غير ذلك.', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_date', 'value' => 'Timing', 'ar' => 'التوقيت', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_date_hint', 'value' => 'In whatever form you have it — a date, a launch, “early next quarter”.', 'ar' => 'بأي صيغة لديك — تاريخ، أو موعد إطلاق، أو «مطلع الربع القادم».', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_location', 'value' => 'Where', 'ar' => 'المكان', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_budget', 'value' => 'Budget', 'ar' => 'الميزانية', 'type' => 'string', 'group' => 'contact_form'],
        ['key' => 'contact.field_budget_hint', 'value' => 'A range is fine. It shapes the plan, not whether you get a reply.', 'ar' => 'يكفي نطاق تقريبي. هو يحدّد شكل الخطة، لا ما إذا كنت ستتلقّى ردًا.', 'type' => 'string', 'group' => 'contact_form'],
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
        ['key' => 'services.count', 'value' => ':count services', 'ar' => ':count خدمات', 'type' => 'string', 'group' => 'services'],
        ['key' => 'services.count_one', 'value' => 'One service', 'ar' => 'خدمة واحدة', 'type' => 'string', 'group' => 'services'],

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
