<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            /*
             * ROOT-RELATIVE, not built from APP_URL.
             *
             * An absolute URL here bakes one host and port into every <img> on
             * the site. APP_URL was http://localhost while the app was served
             * on 127.0.0.1:8000, so every image 404'd in the browser while
             * every test passed — the suite renders HTML and never fetches
             * what the HTML points at.
             *
             * A root-relative path resolves against whatever host served the
             * page, so local, staging and production all work with no
             * configuration. The few places that genuinely need an absolute
             * URL build one at the point of use: see App\Support\AbsoluteUrl.
             */
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'media_originals' => [
            'driver' => 'local',
            'root' => storage_path('app/media/originals'),
            'serve' => false,
            'throw' => true,
        ],

        'media_public' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            // Root-relative for the same reason as the 'public' disk above.
            'url' => '/storage/media',
            'visibility' => 'public',
            'throw' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
