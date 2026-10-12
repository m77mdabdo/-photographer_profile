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
        'galleries' => 'Galleries',
        'journal' => 'Journal',
        'pages' => 'Pages',
        'services' => 'Services',
        'clients' => 'Clients',
        'enquiries' => 'Enquiries',
        'users' => 'Users',
        'settings' => 'Site settings',
        'redirects' => 'Redirects',

        // Two tiers: a module that works in the old panel, and one that does
        // not exist anywhere yet.
        'in_old_panel' => 'old panel',
        'planned' => 'planned',
    ],
];
