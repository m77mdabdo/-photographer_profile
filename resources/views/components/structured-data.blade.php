@props(['data' => null])
@php
    /*
     * JSON-LD blocks, emitted only when there is something true to say.
     *
     * Accepts one block or a list of them. Anything empty is skipped entirely
     * rather than printed as "{}" — an empty graph node is a claim that a
     * thing exists and nothing is known about it, which is worse than silence.
     */
    $blocks = collect(is_array($data) && array_is_list($data) ? $data : [$data])
        ->filter(fn ($block): bool => is_array($block) && $block !== [])
        ->values();

    /*
     * JSON_HEX_TAG escapes < and > to < and >, so a title containing
     * "</script>" cannot break out of the block — the one injection JSON-LD is
     * genuinely prone to. The rest keep URLs and accented characters readable.
     */
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
@endphp
@foreach ($blocks as $block)
<script type="application/ld+json">{!! json_encode($block, $flags) !!}</script>
@endforeach
