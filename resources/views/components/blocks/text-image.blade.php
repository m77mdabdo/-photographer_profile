@props(['block', 'media', 'credits' => null, 'descriptions' => [], 'lead' => false])
<section class="block block--split block--text-image">
    <div class="block__split-text">
        @if (filled($block->data['heading'] ?? null))
            <h2 class="block__heading"{!! \App\Support\Fallback::declare($block->localeOf('heading')) !!}>{{ $block->data['heading'] }}</h2>
        @endif
        @if (filled($block->data['body'] ?? null))
            <div class="block__body"{!! \App\Support\Fallback::declare($block->localeOf('body')) !!}>
                @foreach (preg_split('/\n\s*\n/', trim((string) $block->data['body'])) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
        @endif
    </div>
    @php($image = $media->first())
    @if ($image)
        <div class="block__split-media">
            <x-picture :media="$image" sizes="(max-width: 900px) 100vw, 50vw" img-class="block__image"
                       :alt="$descriptions[0] ?? null"
                   :priority="$lead" />
        </div>
    @endif
</section>
