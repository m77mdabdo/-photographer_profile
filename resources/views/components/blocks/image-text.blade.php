@props(['block', 'media'])
<section class="block block--split block--image-text">
    @php($image = $media->first())
    @if ($image)
        <div class="block__split-media">
            <x-picture :media="$image" sizes="(max-width: 900px) 100vw, 50vw" img-class="block__image" />
        </div>
    @endif
    <div class="block__split-text">
        @if (filled($block->data['heading'] ?? null))
            <h2 class="block__heading">{{ $block->data['heading'] }}</h2>
        @endif
        @if (filled($block->data['body'] ?? null))
            <div class="block__body">
                @foreach (preg_split('/\n\s*\n/', trim((string) $block->data['body'])) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
        @endif
    </div>
</section>
