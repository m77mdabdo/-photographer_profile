@props(['block', 'media', 'credits' => null, 'descriptions' => []])
<section class="block block--text">
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
</section>
