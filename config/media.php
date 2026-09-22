<?php

declare(strict_types=1);

return [
    // Widths generated for each format. Never upscales past the original.
    'widths' => [480, 768, 1080, 1440, 1920, 2560],

    // Modern formats at every width; JPEG only as a narrow fallback.
    'formats' => [
        'avif' => ['quality' => 50, 'widths' => 'all'],
        'webp' => ['quality' => 78, 'widths' => 'all'],
        'jpeg' => ['quality' => 82, 'widths' => [1080, 1920]],
    ],

    // Upload validation.
    'max_upload_kb' => 61440,          // 60 MB
    'max_megapixels' => 80,            // decompression-bomb guard
    'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'accepted_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // Low quality image placeholder.
    'lqip_width' => 16,
    'lqip_quality' => 40,

    'queue' => 'media',
];
