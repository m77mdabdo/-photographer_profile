@props(['block', 'media'])
{{--
    A horizontally scrolling run of images. Scrolling is CSS overflow, so it
    works with a trackpad, a scrollbar, touch, and keyboard focus — no
    carousel script, and nothing hidden if JavaScript never arrives.
--}}
<figure class="block block--gallery-strip">
    <ul class="block__strip" role="list" tabindex="0" aria-label="Image gallery, scrollable">
        @foreach ($media as $image)
            <li class="block__strip-item">
                <x-picture :media="$image" sizes="(max-width: 700px) 80vw, 480px" img-class="block__image" />
            </li>
        @endforeach
    </ul>
    @if (filled($block->data['caption'] ?? null))
        <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
    @endif
</figure>
