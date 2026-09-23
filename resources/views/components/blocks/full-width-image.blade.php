@props(['block', 'media'])
@php($image = $media->first())
@if ($image)
    <figure class="block block--full-width-image">
        <x-picture :media="$image" sizes="100vw" img-class="block__image" />
        @if (filled($block->data['caption'] ?? null))
            <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
        @endif
    </figure>
@endif
