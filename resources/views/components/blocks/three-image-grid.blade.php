@props(['block', 'media', 'credits' => null, 'descriptions' => [], 'lead' => false])
{{--
    Task 38 Part D. Each frame is labelled with its position in the row and,
    from 900px, set at its own depth (components/blocks.css says why the
    offsets cannot widen the page).

    NO ENTRANCE ANIMATION, and that was measured rather than assumed (D2/D3).
    The first version gave the row the existing reveal (motion/reveal.js).
    Project pages carried no data-motion before it, so that one attribute
    pulled GSAP and the reveal module onto every project page: +33.4 kB, and
    LCP on /work/salt-line from 2.41-2.42 s to 2.50-2.56 s on the mobile
    preset. A row that arrives with a fade is not worth a slower first frame
    on every story, so the row arrives as it is.
--}}
@php($frames = $media->take(3))
<figure class="block block--grid">
    <div class="block__grid">
        @foreach ($frames as $i => $image)
            <div class="block__grid-item">
                <x-picture :media="$image" sizes="(max-width: 700px) 100vw, 30vw" img-class="block__image"
                           :alt="$descriptions[$i] ?? null"
                           :priority="$lead && $i === 0" />
                {{-- The figures run left to right inside an isolate: in an Arabic paragraph
                     "01 / 03" is otherwise reordered to read "03 / 01" — measured on the
                     first render. The label itself still sits at the frame's start edge. --}}
                <span class="block__index" aria-hidden="true"><bdi dir="ltr">{{ sprintf('%02d', $loop->iteration) }} / {{ sprintf('%02d', $frames->count()) }}</bdi></span>
            </div>
        @endforeach
    </div>
    @if (filled($block->data['caption'] ?? null))
        <figcaption class="block__caption"{!! \App\Support\Fallback::declare($block->localeOf('caption')) !!}>{{ $block->data['caption'] }}</figcaption>
    @endif
</figure>
