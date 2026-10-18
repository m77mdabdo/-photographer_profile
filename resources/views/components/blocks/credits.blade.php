@props(['block', 'media', 'credits' => null, 'descriptions' => []])
<section class="block block--credits">
    <h2 class="block__heading"{!! \App\Support\Fallback::declare($block->localeOf('heading')) !!}>{{ $block->data['heading'] ?? 'Credits' }}</h2>

    {{--
        Credits are handed in by the page, never read off $block->project.
        Touching that relation here would lazy-load the project and its
        credits from inside a render loop — one extra query per Credits block,
        invisible until a story has several.
    --}}
    @if ($credits !== null && $credits->isNotEmpty())
        <dl class="block__credits">
            @foreach ($credits as $credit)
                <dt>{{ $credit->role }}</dt>
                <dd>{{ $credit->name }}</dd>
            @endforeach
        </dl>
    @endif
</section>
