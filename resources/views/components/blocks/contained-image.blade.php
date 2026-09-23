@props(['block', 'media', 'credits' => null, 'descriptions' => []])
@php($image = $media->first())
@if ($image)
    <figure class="block block--contained-image">
        <x-picture :media="$image" sizes="(max-width: 900px) 100vw, 900px" img-class="block__image"
                   :alt="$descriptions[0] ?? null" />
        @if (filled($block->data['caption'] ?? null))
            <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
        @endif
    </figure>
@endif
