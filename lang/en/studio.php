<?php

declare(strict_types=1);

/**
 * The panel's own strings.
 *
 * Filament ships translations for ITS chrome — "Save", "Create", "Delete",
 * pagination, the table empty states. It does not ship translations for ours,
 * because ours do not exist until we write them. This file and its Arabic twin
 * are that half.
 *
 * This is the first lang/ directory this project has had. ADR 0002 counted 192
 * English surfaces across the application; these are the panel's share of them
 * and the pattern every later one follows.
 */
return [
    'dashboard' => [
        'subheading' => 'What needs you today.',
    ],

    'stats' => [
        'published' => 'Published work',
        'published_hint' => 'Live on the site',
        'drafts' => 'Unfinished work',
        'drafts_hint' => 'Started, not published',
        'enquiries' => 'Unread enquiries',
        'enquiries_hint' => 'Waiting for a reply',
        'article_drafts' => 'Journal drafts',
        'article_drafts_hint' => 'Written, not published',
    ],

    'media' => [
        'description_section' => 'What is in the picture',
        'description_hint' => 'Alt text is read aloud and indexed; a caption is shown beside the image.',
        'credit_section' => 'Credit and framing',
        'focal_x' => 'Focal point X',
        'focal_y' => 'Focal point Y',
        'focal_hint' => '0 is the left edge, 1 the right. Used when a crop has to choose.',
        'filename' => 'File',
        'used_in' => 'Used in',
        'dimensions' => 'Size',
        'upload' => 'Upload images',
        'choose' => 'Choose files',
        'upload_hint' => 'Checked by content, not by extension. Identical files are stored once.',
        'queued' => '":name" is queued for processing.',
        'deduped' => '":name" was already in the library — the existing copy is being used.',
        'refused' => '":name" was refused.',
        'open_failed' => 'Open the failed images',
        'heading' => 'The media pipeline',
        'description' => 'Images still being processed, and any that failed.',
        'pending' => 'queued',
        'processing' => 'processing',
        'failed' => 'failed',
        'no_reason' => 'No reason recorded.',
        'open_library' => 'Open the media library',
    ],

    'recent' => [
        'open_all' => 'All projects',
        'empty_hint' => 'Projects and journal entries you edit will collect here, newest first.',
        'heading' => 'Recently edited',
        'description' => 'Pick up where the studio left off.',
        'empty' => 'Nothing has been edited yet.',
    ],

    'enquiries' => [
        'handling' => 'Handling',
        'who' => 'Who wrote',
        'the_work' => 'The work',
        'message' => 'Message',
        'provenance' => 'Provenance',
        'email' => 'Email',
        'phone' => 'Phone',
        'company' => 'Company',
        'location' => 'Location',
        'budget' => 'Budget',
        'date' => 'Date',
        'source' => 'Source',
        'consent' => 'Consent',
        'no_consent' => 'Not recorded.',
        'origin' => 'Origin',
        'origin_hashed' => 'Recorded as a one-way hash. The address itself is not stored and cannot be shown.',
        'origin_absent' => 'Not recorded.',
        'handling_hint' => 'Where this enquiry has got to, and how they found the studio.',
        'heard_from' => 'Heard from',
        'type' => 'Type',
        'received' => 'Received',
        'empty_hint' => 'Messages from the contact form arrive here. Share the site to start the conversation.',
        'heading' => 'Recent enquiries',
        'description' => 'Unread first, then newest.',
        'empty' => 'No enquiries yet.',
        'unread' => 'unread',
        'open_all' => 'Open all enquiries',
    ],

    'kind' => [
        'project' => 'Project',
        'article' => 'Journal',
    ],

    /*
     * THE FRONT DOOR, IN BOTH LANGUAGES.
     *
     * Fortify owns login, the password reset, the two-factor challenge and the
     * password confirmation. Those four pages SURVIVE the step-10 deletion of
     * the custom admin, so they are part of the shipped product rather than
     * scaffolding — and four of the five shipped with their English typed
     * directly into the markup while the layout set dir="rtl" from the
     * visitor's cookie. See docs/decisions/0004-direction-and-content.md.
     */
    /*
     * Strings that belong to no one screen.
     *
     * The skip link is in all three layouts and was typed in English in two of
     * them and passed through __() with no translation in the third — so an
     * Arabic visitor's first tab stop was an English sentence, on every page
     * of the site.
     */
    /*
     * The two switchers at the foot of every page.
     *
     * Theme::label() returned 'System'/'Light'/'Dark' as PHP literals, and the
     * locale switcher called __('Language') and __('current') with no ar file
     * behind them — so the last two controls on an Arabic page were in
     * English, on every page of the site.
     */
    'controls' => [
        'language' => 'Language',
        'current' => 'current',
        'theme_system' => 'System',
        'theme_light' => 'Light',
        'theme_dark' => 'Dark',
    ],

    /*
     * Each language's name IN THE READER'S LANGUAGE, for the sr-only span.
     *
     * The switcher shows "العربية" visually — a language must be findable by
     * somebody who reads only that language. The announced name was
     * Locale::name(), which is always the ENGLISH name, so an Arabic screen
     * reader said "Arabic" in English. These are the same names translated.
     */
    'language_name' => [
        'en' => 'English',
        'ar' => 'Arabic',
    ],
    'a11y' => [
        'skip' => 'Skip to content',
    ],
    'auth' => [
        'email' => 'Email address',
        'password' => 'Password',
        'remember' => 'Stay signed in on this device',
        'forgot' => 'Forgotten your password?',
        'back' => 'Back to sign in',

        'reset_heading' => 'Reset your password',
        'reset_lede' => 'Enter your email address and we will send a link to set a new password.',
        'reset_send' => 'Send the link',

        'choose_heading' => 'Choose a new password',
        'choose_new' => 'New password',
        'choose_confirm' => 'Confirm the new password',
        'choose_save' => 'Save the new password',

        'confirm_heading' => 'Confirm your password',
        'confirm_lede' => 'This area needs your password again before you continue.',
        'confirm_submit' => 'Continue',

        'challenge_heading' => 'Confirm it is you',
        'challenge_lede' => 'Open your authenticator app and enter the six-digit code it shows.',
        'challenge_code' => 'Authentication code',
        'challenge_recovery' => 'Recovery code',
        'challenge_recovery_hint' => 'Use this only if you cannot reach your authenticator app.',
        'challenge_use_recovery' => 'Use a recovery code instead',
        'challenge_use_code' => 'Use an authentication code instead',
        'challenge_submit' => 'Confirm',
    ],
    'login' => [
        'heading' => 'Sign in',
        'intro' => 'This is the studio side of the website.',
        'note' => 'Two-factor authentication is required.',
    ],


    'nav' => [
        // The five groups, named for what the owner is doing.
        'group_work' => 'The work',
        'group_writing' => 'Writing',
        'group_studio' => 'The studio',
        'group_people' => 'People & messages',
        'group_system' => 'System',

        'projects' => 'Projects',
        'media' => 'Media',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'journal' => 'Journal',
        'article_categories' => 'Journal categories',
        'pages' => 'Pages',
        'services' => 'Services',
        'clients' => 'Clients',
        'enquiries' => 'Enquiries',
        'users' => 'Users',
        'settings' => 'Site settings',
        'redirects' => 'Redirects',

        // Two tiers: a module that works in the old panel, and one that does
        // not exist anywhere yet.
    ],

    'table' => [
        'used' => 'Used',
        'translated' => 'Translated',
        'status' => 'Status',
        'added' => 'Added',
        'updated' => 'Updated',
    ],

    'model' => [
        'media' => 'Image',
        'media_plural' => 'Media',
    ],

    'field' => [
        'published_at' => 'Published at',
        'published_at_hint' => 'Left empty, publishing fills it with now.',
        'slug_hint' => 'The address. Changing it on a published item writes a 301 from the old one.',
        'cover' => 'Cover image',
        'hero' => 'Hero image',
        'featured' => 'Featured image',
        'credit_role' => 'Role',
        'add_credit' => 'Add a credit',
        'canonical' => 'Canonical URL',
        'canonical_hint' => 'Only when this page is a copy of something that lives elsewhere. Leave empty otherwise.',
        'price_from' => 'Price from (pence)',
        'price_hint' => 'In pence, because the column is. 150000 reads as £1,500.',
        'indexable' => 'Indexable',
        'indexable_hint' => 'Off adds a noindex, so search engines leave this page out.',
        'alt' => 'Alt text',
        'caption' => 'Caption',
        'title' => 'Title',
        'name' => 'Name',
        'slug' => 'Slug',
        'subtitle' => 'Subtitle',
        'excerpt' => 'Excerpt',
        'description' => 'Description',
        'body' => 'Body',
        'summary' => 'Summary',
        'standfirst' => 'Standfirst',
        'location' => 'Location',
        'client' => 'Client',
        'price_note' => 'Price note',
        'cta_label' => 'Button label',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
        'value' => 'Value',
    ],

    'section' => [
        'content' => 'Content',
        'imagery' => 'Imagery',
        'imagery_hint' => 'Only images the queue has finished processing can be chosen — a pending one has no renditions to show.',
        'filing' => 'Filing',
        'credits' => 'Credits',
        'credits_hint' => 'Who else made this. Shown on the story in the order you arrange them.',
        'publishing' => 'Publishing',
        'details' => 'Details',
    ],

    'settings' => [
        'group' => [
            'identity' => 'The studio',
            'navigation' => 'Menu labels',
            'hero' => 'Home — the opening frame',
            'home' => 'Home — the sections below',
            'work' => 'The work index',
            'project' => 'A project page',
            'archive' => 'The archive',
            'journal' => 'The journal',
            'services' => 'Services',
            'about' => 'About',
            'contact' => 'Contact',
            'contact_form' => 'Contact — the form itself',
            'footer' => 'Footer & social',
            'shared' => 'Shared across pages',
            'errors' => 'Error pages',
            'attribution' => 'Attribution line',
            'seo' => 'Search & sharing',
        ],

        'group_hint' => [
            'identity' => 'Your name and the sentence that introduces the studio. These appear in the page title, the footer and the share card.',
            'navigation' => 'The words in the menu. Changing one does not change the address it opens.',
            'hero' => 'The first screen on the home page: the stage rail, the frame label and the metadata beside it.',
            'home' => 'The headings and standfirsts of each section further down the home page.',
            'work' => 'The index that lists every published project, and what it says when there is nothing to list yet.',
            'project' => 'The labels beside a project — year, client, location — and the warning on an unpublished preview.',
            'archive' => 'The loose-frames page: its filters, its counter and its empty state.',
            'journal' => 'Written pieces: the index, the headings, and the invitation on the home page.',
            'services' => 'What you offer, how the prices read, and the section on the home page.',
            'about' => 'The about page, including your portrait.',
            'contact' => 'The enquiry form, its consent wording and the message somebody sees after sending it.',
            'contact_form' => 'Every label and hint on the enquiry form. The field types and which are required are structural and stay in code.',
            'footer' => 'The bottom of every page: social links, legal links and the copyright line.',
            'shared' => 'Controls that appear on more than one page — pagination, the colour-scheme switch, read times.',
            'errors' => 'What a visitor reads when a page is missing, private or briefly unavailable.',
            'attribution' => 'The small credit line in the footer.',
            'seo' => 'The description search engines show, and the picture used when a page is shared.',
        ],
        'save' => 'Save settings',
        'saved' => 'Settings saved.',
        'not_translatable' => 'One picture for both languages.',
        'media_hint' => 'Chosen from the media library. Only images the queue has finished processing are offered.',
    ],

    'redirects' => [
        'heading' => 'The address that moved',
        'from' => 'From',
        'to' => 'To',
        'kind' => 'Kind',
        'permanent' => '301 — permanent',
        'temporary' => '302 — temporary',
        'active' => 'Active',
        'origin' => 'Origin',
        'hits' => 'Hits',
        'manual' => 'Added by hand',
        'automatic' => 'From a rename',
        'from_hint' => 'The old address, starting with a slash.',
        'locale_rule' => 'A redirect applies to the language in its address and no other. :arabic moves the Arabic page; the unprefixed path moves the English one.',
        'rule_path' => 'A path starts with / and has no query string — “/old-name”, not “https://example.com/old-name?x=1”.',
        'rule_not_admin_from' => 'Neither admin is ever redirected. A row here could lock the studio out of its own site.',
        'rule_not_admin_to' => 'A public address is never redirected into an admin.',
        'rule_unique' => 'There is already a redirect from that address.',
        'rule_loop' => 'A redirect to itself is a loop.',
    ],

    'users' => [
        'email' => 'Email',
        'role' => 'Role',
        'two_factor' => '2FA',
        'last_seen' => 'Last seen',
        'never' => 'Never',
        'role_hint' => 'An Owner can manage people and delete permanently. An Editor can do everything else.',
        'last_owner' => 'This is the last Owner. Promote somebody else before changing this.',
    ],
];
