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

    /*
     * B1 — the band of figures at the top of the dashboard.
     */
    /*
     * B3 — the untranslated worklist.
     */
    'untranslated' => [
        'heading' => 'Waiting for Arabic',
        'description' => 'Published in English, not yet in :locale. Most missing first.',
        'record' => 'Record',
        'kind' => 'Kind',
        'missing' => 'Fields missing',
        'translate' => 'Translate',
        'untitled' => 'Untitled (#:id)',
        'type' => [
            'project' => 'Project',
            'article' => 'Journal',
            'service' => 'Service',
            'page' => 'Page',
            'articlecategory' => 'Journal subject',
            'category' => 'Category',
            'tag' => 'Tag',
            'client' => 'Client',
            'media' => 'Picture',
            'menuitem' => 'Navigation',
            'setting' => 'Setting',
            'testimonial' => 'Testimonial',
        ],
    ],

    /*
     * B2 — media needing attention.
     */
    'attention' => [
        'heading' => 'Pictures needing attention',
        'description' => 'Encodes that failed or have not finished.',
        'empty' => 'Every picture has processed.',
        'empty_hint' => 'Failed or stuck encodes appear here with the reason. Nothing to do.',
        'reason' => 'What went wrong',
        'no_reason' => 'No reason recorded',
        'stuck' => 'Still processing',
        'retry' => 'Try again',
        'retried' => 'Queued again.',
        'retried_body' => ':name is back in the queue.',
    ],
    'figures' => [
        'label' => 'The site at a glance',
        'inbox' => 'Inbox',
        'work' => 'The work',
        'writing' => 'Writing',
        'pictures' => 'Pictures',
        'ready' => 'Ready',
        'processing' => 'Processing',
        'failed' => 'Failed',
        'total' => 'In the library',
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
        'demo' => 'Stock',
        'demo_note' => 'Stock photograph, not your own work. Remove every one with',
        'demo_only' => 'Stock only',
        'real_only' => 'Your own work only',
        'destroy_warning' => 'Destroying ":name" deletes the original and every rendition from disk. This cannot be undone.',
        'destroy_confirm_label' => 'Type the filename to confirm',
        'destroy_mismatch' => 'That is not the filename. Nothing was deleted.',
        'the_file' => 'The file',
        'usage_count' => 'Not used anywhere|Used on one page|Used on :count pages',
        'usage_short' => '{0}Unused|{1}1 page|[2,*]:count pages',
        'detail' => 'Inspect',
        'reprocess' => 'Reprocess renditions',
        'reprocess_confirm' => 'The renditions are rebuilt from the original. The file on disk is not touched.',
        'reprocess_queued' => 'Queued for reprocessing.',
        'focal_heading' => 'The focal point',
        'focal_handle' => 'Focal point — drag, or use the arrow keys',
        'focal_readout' => 'Focal point: :x, :y',
        'focal_save' => 'Save the focal point',
        'focal_saved' => 'Focal point saved.',
        'camera' => 'What the camera recorded',
        'body' => 'Body and lens',
        'exposure' => 'Exposure',
        'captured' => 'Taken',
        'no_exif' => 'This original carried no camera data.',
        'exif_unread' => 'The original has not been read for camera data yet.',
        'renditions' => 'Renditions',
        'format' => 'Format',
        'width' => 'Width',
        'weight' => 'Weight',
        'no_renditions' => 'No renditions yet.',
        'missing_renditions' => 'Missing: :list. Reprocess to rebuild them.',
        'pictured' => 'What is in it',
        'no_alt' => 'No alt text yet',
        'description_section' => 'What is in the picture',
        'description_hint' => 'Alt text is read aloud and indexed; a caption is shown beside the image.',
        'credit_section' => 'Credit and framing',
        'focal_x' => 'Focal point X',
        'focal_y' => 'Focal point Y',
        'focal_hint' => 'Drag the handle, click the picture, or focus the handle and use the arrow keys — 1% a step, 10% with Shift. 0 is the left edge, 1 the right. This is the point every crop keeps.',
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
        'editor' => 'Changed by',
        'open_all' => 'All projects',
        'empty_hint' => 'Projects and journal entries you edit will collect here, newest first.',
        'heading' => 'Recently edited',
        'description' => 'Pick up where the studio left off.',
        'empty' => 'Nothing has been edited yet.',
    ],

    'enquiries' => [
        'change_status' => 'Change status',
        'status' => 'Status',
        'status_changed' => 'Status set to :status.',
        'notes' => 'Your notes',
        'add_note' => 'Add a note',
        'note' => 'Note',
        'note_added' => 'Note added.',
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
        'language_menu' => 'Language: :language',
        'menu_open' => 'Menu',
        'menu_close' => 'Close',
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
        'setup_heading' => 'Two-factor authentication required',
        'setup_lede' => 'Enrol an authenticator app, then confirm a code to finish. You will be asked for a code from it each time you sign in.',
        'setup_begin' => 'Begin setup',
        'setup_confirm' => 'Confirm',
    ],
    'login' => [
        'heading' => 'Sign in',
        'intro' => 'This is the studio side of the website.',
        'note' => 'Two-factor authentication is required.',
    ],


    /*
     * A5 — the one link to the custom admin, labelled for what it is.
     *
     * Not "Old admin" and not "Legacy": those name the software's history,
     * which is the studio's problem only if we make it theirs. It names the
     * tools that are actually there.
     */
    'legacy' => [
        'label' => 'Media tools',
        'hint' => 'The few picture tools not moved yet: choosing by thumbnail, the focal-point control, camera data, renditions and the trash.',
    ],
    'nav' => [
        'activity' => 'Activity',
        'testimonials' => 'Testimonials',
        'menus' => 'Navigation',
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

        /*
     * 34d B2 — a badge never carries its meaning in a colour or a glyph
     * alone. These are the words the counts and the translation state say
     * for themselves; the colour stays underneath as the glance.
     */
    /* 34d A3 — the only word a breadcrumb adds that is not a place. */
    'clients' => [
        'no_website' => 'No website',
    ],

    'breadcrumb' => [
        'new' => 'New :thing',
    ],

    'table' => [
        'used_count' => '{0}Unused|{1}1 use|[2,*]:count uses',
        'translation_complete' => ':language complete',
        'translation_missing' => ':language — :count missing',

        'used' => 'Used',
        'translated' => 'Translated',
        'status' => 'Status',
        'added' => 'Added',
        'updated' => 'Updated',
    ],

    'model' => [
        'project' => 'Project',
        'article' => 'Journal entry',
        'service' => 'Service',
        'page' => 'Page',
        'category' => 'Category',
        'article_category' => 'Journal section',
        'tag' => 'Tag',
        'client' => 'Client',
        'inquiry' => 'Enquiry',
        'redirect' => 'Redirect',
        'user' => 'Person',
        'project_plural' => 'Projects',
        'article_plural' => 'Journal',
        'service_plural' => 'Services',
        'page_plural' => 'Pages',
        'category_plural' => 'Categories',
        'article_category_plural' => 'Journal sections',
        'tag_plural' => 'Tags',
        'client_plural' => 'Clients',
        'inquiry_plural' => 'Enquiries',
        'redirect_plural' => 'Redirects',
        'user_plural' => 'People',
        'activity' => 'Activity entry',
        'activities' => 'Activity',
        'testimonial' => 'Testimonial',
        'testimonials' => 'Testimonials',
        'menu' => 'Menu',
        'menus' => 'Navigation',
        'media' => 'Image',
        'media_plural' => 'Media',
    ],

    'field' => [
        'quote' => 'Quote',
        'attribution' => 'Who said it',
        'role' => 'Their role or company',
        'credit' => 'Credit',
        'copyright' => 'Copyright',
        'website' => 'Website',
        'year' => 'Year',
        'price_from_short' => 'From',
        'published_at' => 'Published at',
        'published_at_hint' => 'Left empty, publishing fills it with now.',
        'slug_hint' => 'The address. Changing it on a published item writes a 301 from the old one.',
        'slug_required' => 'Every language needs an address. Leave none of them empty.',
        'slug_taken' => 'Another record already uses that address in this language. Addresses are unique per language.',
        'slug_format' => 'Lowercase letters, numbers and single hyphens — “salt-line”. No spaces, slashes or capitals.',
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

    /*
     * The story builder, mounted in the panel (Task 32, Part 6 step 1).
     *
     * `scope_*` is the two-save-buttons answer: this page saves the STORY and
     * nothing else, and says so rather than leaving an owner to discover it.
     */
    /*
     * 35 H4 — THE STORY BUILDER'S BLOCK TYPES.
     *
     * BlockType::label() returned these as English literals, and the builder
     * is a Livewire component rather than a Filament screen, so the Arabic
     * scan that found the other four enums never looked at it.
     */
    /*
     * 35 H4 — THE MAIL SUBJECTS AND THE CONTACT FORM'S REFUSALS.
     *
     * Both were English literals in PHP: a subject line in an inbox and the
     * eleven sentences a member of the public meets when a submission fails.
     * A validation message only exists when something goes wrong, which is
     * why no render scan had ever seen one.
     */
    'mail' => [
        'received_subject' => 'Enquiry from :name',
        'acknowledged_subject' => 'Your enquiry to :studio',
    ],

    'contact_errors' => [
        'name_required' => 'Please give a name to reply to.',
        'name_max' => 'That name is longer than the form can store — 120 characters is the limit.',
        'email_required' => 'An email address is needed, or there is no way to answer you.',
        'email_invalid' => 'That email address does not look complete — check for a typo.',
        'email_max' => 'That email address is longer than 190 characters, which is longer than any real one.',
        'date_max' => 'That is longer than the date box can hold — put the detail in the message instead.',
        'message_required' => 'Please say something about the work — this is the part that gets read first.',
        'message_min' => 'A little more detail would help: what the pictures are for, and roughly when.',
        'message_max' => 'That message is over 5,000 characters. Send the essentials and the rest can follow by email.',
        'consent' => 'Please confirm you are happy for your details to be kept, so the enquiry can be answered.',
    ],

    'block' => [
        'full_width_image' => 'Full-width image',
        'contained_image' => 'Contained image',
        'portrait_pair' => 'Portrait pair',
        'landscape_pair' => 'Landscape pair',
        'three_image_grid' => 'Three-image grid',
        'image_text' => 'Image with text',
        'text_image' => 'Text with image',
        'full_width_text' => 'Full-width text',
        'quote' => 'Pull quote',
        'video' => 'Video',
        'embed' => 'Embed',
        'spacer' => 'Spacer',
        'credits' => 'Credits',
        'gallery_strip' => 'Gallery strip',
        'group_image' => 'Image',
        'group_text' => 'Text',
        'group_media' => 'Media',
    ],

    'story' => [
        'block_count' => 'block|blocks',
        'story' => 'Story',
        'outline' => 'Outline',
        'add_block' => 'Add a block',
        'save' => 'Save story',
        'saving' => 'Saving…',
        'undo' => 'Undo',
        'redo' => 'Redo',
        'undo_hint' => 'Undo (Ctrl/Cmd+Z)',
        'redo_hint' => 'Redo (Ctrl/Cmd+Shift+Z)',
        'collapse_all' => 'Collapse all',
        'expand_all' => 'Expand all',
        'unsaved' => 'Unsaved changes',
        'editing' => 'Editing',
        'editing_language' => 'Editing language',
        'locale_note' => 'Fields show this language only. Saving leaves the other languages untouched.',
        'stale' => 'This story changed elsewhere after you opened it. Nothing has been overwritten.',
        'offline' => 'Could not reach the server.',
        'offline_safe' => 'Your work is safe — nothing was lost.',
        'offline_retry' => 'Try again.',
        'restore_draft' => 'Restore the draft',
        'discard_draft' => 'Discard it',
        'duplicate' => 'Duplicate',
        'remove' => 'Remove',
        'confirm_remove' => 'Yes, remove it',
        'cancel' => 'Cancel',
        'move_up' => 'Move block up',
        'move_down' => 'Move block down',
        'images' => 'Images',
        'add_images' => 'Add images',
        'alt_for_image' => 'Alt text for this image',
        'move_image_earlier' => 'Move image earlier',
        'move_image_later' => 'Move image later',
        'remove_image' => 'Remove image',
        'picker_close' => 'Close',
        'picker_search' => 'Search',
        'picker_clear' => 'Clear',
        'picker_search_hint' => 'Filename, alt text or credit',
        'picker_keys' => 'Space toggles an image. Shift+click, or Shift+Enter, extends the selection',
        'picker_empty' => 'No processed images match. Only images that finished processing can be used.',
        'title' => 'The story',
        'subheading' => 'Arrange the pictures and the words. Drag to reorder, or use the arrow keys.',
        'action' => 'Story',
        'preview' => 'Preview',
        'scope_heading' => 'This page saves the story on its own.',
        'scope_body' => 'The blocks below are saved by the button at the end of the story — not by the project’s own Save. The title, the cover and the publishing dates are on the project’s details page.',
        'back_to_details' => 'Back to the project’s details',
        'blocks' => 'blocks',
    ],
    'settings' => [
        'group' => [
            'identity' => 'The studio',
            'navigation' => 'Menu labels',
            'hero' => 'Home — the opening screen',
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
            'hero' => 'The first screen on the home page: the process rail, the featured-project label and the details beside it.',
            'home' => 'The headings and standfirsts of each section further down the home page.',
            'work' => 'The index that lists every published project, and what it says when there is nothing to list yet.',
            'project' => 'The labels beside a project — year, client, location — and the warning on an unpublished preview.',
            'archive' => 'The image archive page: its filters, its counter and its empty state.',
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
        'live' => 'Live',
        'paused' => 'Paused',
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
        'two_factor_on' => 'Set up',
        'two_factor_off' => 'Not set up',
        'email' => 'Email',
        'role' => 'Role',
        'two_factor' => '2FA',
        'last_seen' => 'Last seen',
        'never' => 'Never',
        'role_hint' => 'An Owner can manage people and delete permanently. An Editor can do everything else.',
        'last_owner' => 'This is the last Owner. Promote somebody else before changing this.',
    ],

    'filter' => [
        'all' => 'All',
        'featured' => 'Featured',
        'category' => 'Section',
        'uncategorised' => 'No section',
        'active' => 'Shown on the site',
        'unread' => 'Read',
        'unread_only' => 'Unread only',
        'read_only' => 'Read only',
        'usage' => 'Used anywhere',
        'used_only' => 'In use',
        'unused_only' => 'Not used anywhere',
        'unused_tags' => 'Use',
    ],

    'pages' => [
        'locked' => 'Locked',
        'locked_hint' => 'The footer links to this page by name, so it cannot be deleted.',
    ],

    'services' => [
        'on_request' => 'On request',
    ],

    'picker' => [
        'none_chosen' => 'No image chosen.',
        'choose' => 'Choose an image',
        'replace' => 'Replace',
        'clear' => 'Remove',
    ],

    'hero_check' => [
        'title' => 'On the home page',
        'lede' => 'The hero interlocks the studio name with :name. Here is how it will behave. None of this stops you publishing.',
        'good' => 'Good:',
        'worth_knowing' => 'Worth knowing:',
    ],

    'menus' => [
        'item_count' => '{0}No links|{1}1 link|[2,*]:count links',
        'live_count' => ':count of :total work',

        'where' => 'Where',
        'items' => 'Links',
        'add' => 'Add a link',
        'label' => 'Label',
        'type' => 'Kind',
        'type_route' => 'A section of this site',
        'type_page' => 'A page you have written',
        'type_external' => 'Somewhere else',
        'destination' => 'Goes to',
        'url' => 'Address',
        'url_hint' => 'The full address, beginning with https://',
        'shown' => 'Shown',
        'place' => 'Where',
        'live' => 'Working',
        'refuse_create' => 'There are two menus because the design has two places for one. A third would be a menu nothing draws.',
        'refuse_delete' => 'A menu is a place in the design, so it cannot be deleted. Remove its links instead, or switch them off.',
        'place' => [
            'header' => 'The strip at the top',
            'footer' => 'The foot of the page',
        ],

    ],

    'testimonials' => [
        'words' => 'The words',
        'quote' => 'Quote',
        'who' => 'Who said it',
        'portrait' => 'Portrait',
        'portrait_hint' => 'Optional. Most clients do not send one, and a row of empty circles is worse than none.',
        'heading' => 'What clients say',
    ],

    'activity' => [
        'when' => 'When',
        'what' => 'What happened',
        'subject' => 'What changed',
        'who' => 'Who',
        'detail' => 'Detail',
        'since' => 'Since',
        'system' => 'The system',
        'deleted_actor' => ':name (account removed)',
        'refuse_write' => 'The activity log is a record of what happened. Nothing here can be written or changed by hand.',
        'refuse_delete' => 'An audit trail somebody can delete is not an audit trail. Entries are removed only by the retention window — see activity:prune.',

        'event' => [
            'published' => 'Published',
            'unpublished' => 'Unpublished',
            'deleted' => 'Moved to trash',
            'destroyed' => 'Deleted for good',
            'restored' => 'Restored',
            'role_changed' => 'Role changed',
            'user_added' => 'Person added',
            'setting_changed' => 'Setting changed',
            'signed_in' => 'Signed in',
            'signed_out' => 'Signed out',
            'sign_in_failed' => 'Sign-in refused',
        ],

        'kind' => [
            'Project' => 'Project',
            'Article' => 'Journal entry',
            'Service' => 'Service',
            'Page' => 'Page',
            'Testimonial' => 'Testimonial',
            'Media' => 'Image',
            'Menu' => 'Menu',
            'MenuItem' => 'Navigation link',
            'User' => 'Person',
            'Setting' => 'Setting',
        ],
    ],

    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'inquiry_status' => [
        'new' => 'New',
        'read' => 'Read',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'booked' => 'Booked',
        'closed' => 'Closed',
        'spam' => 'Spam',
        'new_note' => 'Arrived, not yet read.',
        'read_note' => 'Read, no reply sent yet.',
        'contacted_note' => 'Replied to, waiting on them.',
        'qualified_note' => 'Real work, dates and budget discussed.',
        'booked_note' => 'In the diary.',
        'closed_note' => 'Finished, declined or gone quiet.',
        'spam_note' => 'Not a real enquiry.',
    ],

    'media_status' => [
        'pending' => 'In queue',
        'processing' => 'Processing',
        'ready' => 'Ready',
        'failed' => 'Failed',
    ],

    'role' => [
        'owner' => 'Owner',
        'editor' => 'Editor',
    ],

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * E2 — AN EMPTY TABLE SAYS WHAT WILL APPEAR AND WHAT TO DO MEANWHILE.
     *
     * Filament's default is an ✗ in a circle over "No Testimonials" and
     * "Create a Testimonial to get started." Three things are wrong with it
     * and only one is cosmetic:
     *
     *   · an ✗ is the mark for something WRONG. An empty list is not an
     *     error, it is a studio that has not done that thing yet.
     *   · "No Testimonials" names the model class, in the plural Filament
     *     derived. It is the software talking about its own tables.
     *   · "to get started" is filler. It tells somebody nothing they did not
     *     know from the empty table in front of them.
     *
     * What an owner needs from an empty screen is what will appear here, and
     * whether there is anything to do now — the standard Task 33 B5 set for
     * the dashboard's empty states, applied to the lists.
     *
     * Each resource names its own icon, and never a cross. The pairing of a
     * _heading with a _body, the parity of this group with the Arabic one,
     * and the fact that every resource table sets all three, are asserted in
     * tests/Feature/Studio/PanelEmptyStateTest.php — a table added later
     * without an empty state fails there rather than shipping the default.
     * ═══════════════════════════════════════════════════════════════════════
     */
    /*
     * Task 34b — the panel's own language switcher, and the words that keep
     * it apart from the per-field content tabs. 'interface' is the one the
     * control wears; 'content' is what Translatable::tabs() announces.
     */
    'locale' => [
        'interface' => 'Interface',
        'interface_legend' => 'Interface language',
        'content_legend' => 'Content language',
    ],

    'empty' => [
        'menu_heading' => 'No menus',
        'menu_body' => 'A menu is the set of links in the header or the footer. Both are seeded; edit one to change what a visitor sees.',
        'project_heading' => 'No work published yet',
        'project_body' => 'A project is a set of photographs with a story around them. The home page and the archive both fill themselves from here.',
        'article_heading' => 'The journal is empty',
        'article_body' => 'Writing about the work is how people find it. An entry appears on the journal page the moment it is published.',
        'service_heading' => 'Nothing offered yet',
        'service_body' => 'What the studio does, and roughly what it costs. These become the services page and the strip on the home page.',
        'page_heading' => 'No pages yet',
        'page_body' => 'A page is standing text at its own address — about, terms, how you work. The privacy notice and the terms are seeded and locked.',
        'testimonial_heading' => 'No testimonials yet',
        'testimonial_body' => 'When a client says something worth keeping, put it here. A strip appears on the home page, before the invitation to get in touch, as soon as there is one.',
        'inquiry_heading' => 'No enquiries yet',
        'inquiry_body' => 'Messages from the contact form arrive here, unread first. Nothing needs doing until one does.',
        'media_heading' => 'The library is empty',
        'media_body' => 'Upload photographs and they are processed into every size the site needs. Everything else on this list draws from here.',
        'redirect_heading' => 'No redirects',
        'redirect_body' => 'One is written automatically whenever a published address changes, so an old link keeps working. You can add your own.',
        'tag_heading' => 'No tags yet',
        'tag_body' => 'A tag groups journal entries across sections. They appear as you write.',
        'client_heading' => 'No clients listed',
        'client_body' => 'Names shown on the home page, in the order you set. Only add the ones you are happy to name.',
        'article_category_heading' => 'No journal sections yet',
        'article_category_body' => 'A section files the writing — notes, lighting, behind the scenes. The journal lists only the sections that have something published in them.',
        'category_heading' => 'No categories yet',
        'category_body' => 'A category files the work — product, advertising, interiors. The archive offers only the ones with published work in them.',
        'activity_heading' => 'Nothing recorded yet',
        'activity_body' => 'Publishing, deletions, role changes, settings and sign-ins appear here as they happen. Entries older than a year are removed automatically.',
    ],

    /*
     * Cinematic sections (lighting brief, 22 Oct 2026). Plain words for an
     * owner: every lighting choice is a named preset, never a number.
     */
    'cinematic' => [
        'nav' => 'Cinematic sections',
        'model' => 'cinematic section',
        'model_plural' => 'cinematic sections',
        'section_kind' => 'What this section is',
        'type_label' => 'Kind',
        'type_hint' => 'A lighting gallery reveals 4 or 5 photographs as the visitor scrolls. A featured image or video fills the width of the page.',
        'type' => [
            'gallery' => 'Lighting gallery',
            'image' => 'Featured image',
            'video' => 'Featured video',
        ],
        'active' => 'Shown on the home page',
        'active_hint' => 'Switch off to hide this section without deleting it.',
        'section_words' => 'Words',
        'words_hint' => 'A short title and an optional sentence, in English and Arabic. Leave both empty to show the pictures alone.',
        'section_photographs' => 'Photographs',
        'photographs_hint' => 'Choose 4 or 5 photographs from the media library. Drag them, or use the arrows, to change the order the light reaches them.',
        'photographs' => 'Photographs',
        'photograph' => 'Photograph',
        'add_photograph' => 'Add a photograph',
        'section_featured_image' => 'Featured image',
        'featured_image' => 'Image',
        'section_featured_video' => 'Featured video',
        'video_hint' => 'An MP4 or WebM file, at most 60 MB. It plays silently and on a loop, and only while it is on screen.',
        'video' => 'Video file',
        'poster' => 'Poster image',
        'poster_hint' => 'Shown before the video plays, and instead of it when a visitor prefers less motion.',
        'section_lighting' => 'Lighting',
        'lighting_hint' => 'How the light arrives as the visitor scrolls. Every image is fully visible to visitors who prefer less motion.',
        'direction_label' => 'Light travels',
        'direction_hint' => 'Which way the light moves across the screen. A fixed choice applies to both languages.',
        'direction' => [
            'auto' => 'Follow the language (left to right in English, right to left in Arabic)',
            'ltr' => 'Left to right',
            'rtl' => 'Right to left',
        ],
        'intensity_label' => 'Strength of the light',
        'intensity' => [
            'soft' => 'Soft',
            'balanced' => 'Balanced',
            'strong' => 'Strong',
        ],
        'darkness_label' => 'How dark the other photographs are',
        'darkness_hint' => 'With the spotlight on: Subtle 85%, Deep 95%, Night 98% darker than the lit photograph.',
        'darkness' => [
            'subtle' => 'Subtle',
            'deep' => 'Deep',
            'night' => 'Night',
        ],
        'pace_label' => 'Scroll distance per photograph',
        'pace' => [
            'slow' => 'Slow',
            'natural' => 'Natural',
            'quick' => 'Quick',
        ],
        'overlay_label' => 'Shadow colour',
        'overlay' => [
            'none' => 'Neutral',
            'ink' => 'Ink',
            'warm' => 'Warm',
            'cool' => 'Cool',
        ],
        'mobile_label' => 'On phones',
        'mobile' => [
            'track' => 'Moving strip (recommended)',
            'grid' => 'Two columns',
            'stack' => 'One photograph per row',
        ],
        'animate' => 'Animate the light',
        'spotlight' => 'Exclusive spotlight',
        'spotlight_hint' => 'One photograph lit at a time; the light moves from the first to the last as the visitor scrolls. Off: every photograph lights in turn and stays lit.',
        'softness_label' => 'Transition',
        'softness' => [
            'crisp' => 'Crisp',
            'soft' => 'Soft',
            'gentle' => 'Gentle',
        ],
        'mobile_width_label' => 'Photograph width on phones',
        'mobile_width' => ':percent% of the screen',
        'shown' => 'Shown',
        'hidden' => 'Hidden',
        'animate_hint' => 'Off shows the photographs fully lit, with no movement.',
        'empty_heading' => 'No cinematic sections yet',
        'empty_body' => 'Create a lighting gallery or a featured image or video. Sections appear on the home page in the order of this list.',
    ],

    'security' => [
        'nav' => 'Account security',
        'lead' => 'Recovery codes let you sign in if you lose the phone with your authenticator app. Each code works once. Keep them somewhere safe and offline.',
        'show' => 'Show recovery codes',
        'regenerate' => 'Generate new codes',
        'password' => 'Your password',
        'confirm_hint' => 'Type your password to show your recovery codes.',
        'regenerate_hint' => 'New codes replace the old ones, which stop working immediately.',
        'regenerated' => 'New recovery codes generated. Save them now.',
        'codes_label' => 'Your recovery codes',
        'save_hint' => 'Save these now — in a password manager, or printed and kept safe. They are hidden again when you leave this page.',
        'hidden' => 'Your codes are hidden. Press “Show recovery codes” and confirm your password to see them.',
        'password_hint' => 'To change your password, name or email address, open your',
        'profile_link' => 'profile',
    ],

    'help' => [
        'what_is_this' => 'What is this?',
        'close' => 'Close',
        'read_more' => 'Read more in Help',
        'no_results' => 'Nothing in the guide matches “:q”. Try another word.',
        'nav' => 'Help',
        'search' => 'Search the guide',
        'contents' => 'Contents',
        'missing' => 'The guide could not be found.',
    ],

    'help_hint' => [
        'status' => ['title' => 'Status', 'body' => 'Draft: saved, not on the website. Published: on the website from the “Published at” date (a future date schedules it). Archived: kept, not on the website.'],
        'seo_title' => ['title' => 'SEO title', 'body' => 'The title shown by Google and in the browser tab. About 50–60 characters. Leave empty to use the item’s own title.'],
        'seo_description' => ['title' => 'SEO description', 'body' => 'The sentence shown under the title in search results and link previews. About 150 characters. Leave empty to use the summary or excerpt.'],
        'alt' => ['title' => 'Alt text', 'body' => 'One sentence describing what is in the photograph, read aloud to blind visitors and used by search engines. Describe the picture, not the project.'],
        'direction' => ['title' => 'Light travels', 'body' => 'Which way the light moves across the screen. “Follow the language” goes left to right in English and right to left in Arabic, matching how each is read.'],
        'spotlight' => ['title' => 'Exclusive spotlight', 'body' => 'On: one photograph lit at a time, the light moving on as the visitor scrolls. Off: every photograph lights in turn and stays lit.'],
        'darkness' => ['title' => 'How dark the other photographs are', 'body' => 'How dark the photographs the light is not on look: Subtle 85%, Deep 95%, Night 98%. Visitors who prefer less motion always see every photograph fully lit.'],
        'softness' => ['title' => 'Transition', 'body' => 'How gradually the light hands over from one photograph to the next. Crisp is quick, Gentle is slow.'],
        'pace' => ['title' => 'Scroll distance per photograph', 'body' => 'How far the visitor scrolls to move the light on by one photograph. Slow gives each photograph more time.'],
        'mobile' => ['title' => 'On phones', 'body' => 'Moving strip: the photographs slide sideways as the visitor scrolls down, the lit one centred. Two columns or one per row: a still grid.'],
        'video' => ['title' => 'Video file', 'body' => 'An MP4 or WebM file up to 60 MB, ideally 10–30 seconds. It always plays muted and on a loop, only while on screen. The poster image is shown before it plays.'],
    ],
];
