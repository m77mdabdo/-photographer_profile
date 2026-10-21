@props(['block', 'media', 'credits' => null, 'descriptions' => [], 'statement' => false])
{{--
    Task 41 5.2: a quotation in a story is that story's statement — the words
    light as they scroll through — unless the block before it was one too
    (projects/show decides: never two in a row). The text is the record's.
--}}
<figure class="block block--quote">
    <blockquote class="block__quote">
        @if ($statement)
            <x-statement><span{!! \App\Support\Fallback::declare($block->localeOf('quote')) !!}>{{ $block->data['quote'] ?? '' }}</span></x-statement>
        @else
            <p{!! \App\Support\Fallback::declare($block->localeOf('quote')) !!}>{{ $block->data['quote'] ?? '' }}</p>
        @endif
    </blockquote>
    @if (filled($block->data['attribution'] ?? null))
        <figcaption class="block__attribution"{!! \App\Support\Fallback::declare($block->localeOf('attribution')) !!}>— {{ $block->data['attribution'] }}</figcaption>
    @endif
</figure>
