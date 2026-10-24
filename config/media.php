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

    /*
     * Upload ceiling. This is a MEMORY ceiling wearing a decompression-bomb
     * costume: GD decodes the whole bitmap before anything can be measured,
     * and measurement puts that at roughly 29.5 MB of resident memory per
     * megapixel. 80 MP therefore implied a ~2.4 GB decode that nothing
     * guarded — an OOM the kernel would deliver before PHP could report it.
     *
     * 50 MP accommodates every current full-frame and most medium-format
     * cameras (a 61 MP A7R is the notable exception and would be refused),
     * bounds a single decode at roughly 1.5 GB, and refuses the 225 MP
     * panorama that prompted this at the door rather than in the worker.
     */
    'max_megapixels' => 50,

    /*
     * The working budget. Once decoded, the source is scaled to at most this
     * many megapixels ONCE, and every rendition is generated from that copy
     * rather than from the original.
     *
     * 16 MP is ~4900x3266 for a 3:2 frame, which is 1.9x the longest variant
     * edge (2560px) in linear terms — ample oversampling for a clean
     * downsample, and far cheaper than decoding 50 MP fourteen times over.
     * The file on disk is never touched; this is an in-memory working copy.
     */
    'working_megapixels' => 16,
    'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'accepted_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // The single source of truth for file extensions, keyed both by detected
    // mime type (for originals) and by variant format (for renditions).
    'extensions' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'avif' => 'avif',
        'webp' => 'webp',
        'jpeg' => 'jpg',
    ],

    // Low quality image placeholder.
    'lqip_width' => 16,
    'lqip_quality' => 40,

    'queue' => 'media',
];
