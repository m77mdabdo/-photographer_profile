@props(['block', 'credits' => null, 'descriptions' => null, 'lead' => false])
@php
    // The enum value maps one-to-one onto a component file name, so adding a
    // block type means adding a view — never editing a switch.
    $component = 'blocks.'.str_replace('_', '-', $block->type->value);
    $media = $block->media();

    // Per-position alt overrides, stored under a key the media guard permits.
    $descriptions = $descriptions ?? (array) ($block->data['descriptions'] ?? []);
@endphp
<x-dynamic-component :component="$component"
                     :block="$block"
                     :media="$media"
                     :credits="$credits"
                     :descriptions="$descriptions"
                     {{-- The first block on the page that actually holds an
                          image owns the LCP; every other picture stays lazy. --}}
                     :lead="$lead" />
