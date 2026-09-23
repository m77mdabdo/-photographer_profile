@props(['block', 'media', 'credits' => null, 'descriptions' => [], 'lead' => false])
<figure class="block block--grid">
    <div class="block__grid">
        @foreach ($media->take(3) as $i => $image)
            <x-picture :media="$image" sizes="(max-width: 700px) 100vw, 30vw" img-class="block__image"
                       :alt="$descriptions[$i] ?? null"
                       :priority="$lead && $i === 0" />
        @endforeach
    </div>
    @if (filled($block->data['caption'] ?? null))
        <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
    @endif
</figure>
