<?php

declare(strict_types=1);

return [
    /*
     * Global SEO defaults. A settings table will replace this file later; until
     * then every default the resolver falls back to lives here and nowhere else.
     */
    'site_name' => env('SEO_SITE_NAME', 'Photographer'),

    'default_title' => env('SEO_DEFAULT_TITLE', 'Photographer'),

    'default_description' => env(
        'SEO_DEFAULT_DESCRIPTION',
        'Photography portfolio, archive and journal.'
    ),

    'default_robots' => 'index, follow',

    'default_og_image' => null,

    'twitter_handle' => null,

    'locale' => 'en',
];
