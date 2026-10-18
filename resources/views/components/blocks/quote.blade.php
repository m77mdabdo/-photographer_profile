@props(['block', 'media', 'credits' => null, 'descriptions' => []])
<figure class="block block--quote">
    <blockquote class="block__quote">
        <p{!! \App\Support\Fallback::declare($block->localeOf('quote')) !!}>{{ $block->data['quote'] ?? '' }}</p>
    </blockquote>
    @if (filled($block->data['attribution'] ?? null))
        <figcaption class="block__attribution"{!! \App\Support\Fallback::declare($block->localeOf('attribution')) !!}>— {{ $block->data['attribution'] }}</figcaption>
    @endif
</figure>
