@props(['block', 'media', 'credits' => null, 'descriptions' => [], 'lead' => false])
<figure class="block block--pair block--landscape-pair">
    <div class="block__pair">
        @foreach ($media->take(2) as $i => $image)
            <x-picture :media="$image" sizes="(max-width: 700px) 100vw, 45vw" img-class="block__image"
                       :alt="$descriptions[$i] ?? null"
                       :priority="$lead && $i === 0" />
        @endforeach
    </div>
    @if (filled($block->data['caption'] ?? null))
        <figcaption class="block__caption"{!! \App\Support\Fallback::declare($block->localeOf('caption')) !!}>{{ $block->data['caption'] }}</figcaption>
    @endif
</figure>
