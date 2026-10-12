<?php

declare(strict_types=1);

/*
 * Pagination, in Arabic.
 *
 * The arrows are NOT mirrored here and that is deliberate: Laravel renders
 * these inside a document whose dir is already rtl, so the browser's
 * bidirectional algorithm reverses the visual order of the label and its
 * arrow for us. Writing « on "next" would reverse it twice and point both
 * controls the same way.
 */

return [
    'previous' => '&laquo; السابق',
    'next' => 'التالي &raquo;',
];
