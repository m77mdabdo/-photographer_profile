@props(['block', 'media', 'credits' => null, 'descriptions' => [], 'lead' => false])
{{--
    A horizontally scrolling run of images. Scrolling is CSS overflow, so it
    works with a trackpad, a scrollbar, touch, and keyboard focus — no
    carousel script, and nothing hidden if JavaScript never arrives.
--}}
<figure class="block block--gallery-strip">
    <ul class="block__strip" role="list" tabindex="0" aria-label="{{ \App\Support\SiteCopy::get('shared.gallery_label') }}">
        @foreach ($media as $i => $image)
            <li class="block__strip-item">
                <x-picture :media="$image" sizes="(max-width: 700px) 80vw, 480px" img-class="block__image"
                           :alt="$descriptions[$i] ?? null"
                       :priority="$lead && $i === 0" />
            </li>
        @endforeach
    </ul>
    @if (filled($block->data['caption'] ?? null))
        <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
    @endif
</figure>
