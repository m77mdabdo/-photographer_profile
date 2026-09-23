<?php

declare(strict_types=1);

return [
    /*
     * Which hero composition the public home page uses.
     *
     * 'a' — the horizontal wordmark spanning the viewport, interrupted by an
     *       opaque plate sitting in grid columns chosen by the image's shape.
     * 'b' — the wordmark set on two lines against the left edge, with the
     *       photograph bleeding off the opposite screen edge.
     *
     * This is the single switch. Changing the live hero is this line; deleting
     * the losing variant afterwards is one commit that removes its component,
     * its stylesheet block and this key.
     */
    'hero_variant' => env('HERO_VARIANT', 'a'),
];
