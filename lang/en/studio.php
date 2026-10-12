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
        'heading' => 'The media pipeline',
        'description' => 'Images still being processed, and any that failed.',
        'pending' => 'queued',
        'processing' => 'processing',
        'failed' => 'failed',
        'no_reason' => 'No reason recorded.',
        'open_library' => 'Open the media library',
    ],

    'recent' => [
        'heading' => 'Recently edited',
        'description' => 'Pick up where the studio left off.',
        'empty' => 'Nothing has been edited yet.',
    ],

    'enquiries' => [
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
        'work' => 'The work',
        'words' => 'Words',
        'studio' => 'The studio',
        'settings' => 'Settings',
        'not_moved' => 'not moved yet',
        'projects' => 'Projects',
        'media' => 'Media',
        'journal' => 'Journal',
        'pages' => 'Pages',
        'services' => 'Services',
        'enquiries' => 'Enquiries',
        'clients' => 'Clients',
        'site_settings' => 'Site settings',
        'redirects' => 'Redirects',
    ],
];
