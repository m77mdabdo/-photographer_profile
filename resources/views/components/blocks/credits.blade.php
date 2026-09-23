@props(['block', 'media'])
<section class="block block--credits">
    <h2 class="block__heading">{{ $block->data['heading'] ?? 'Credits' }}</h2>
    @if ($block->project?->credits?->isNotEmpty())
        <dl class="block__credits">
            @foreach ($block->project->credits as $credit)
                <dt>{{ $credit->role }}</dt>
                <dd>{{ $credit->name }}</dd>
            @endforeach
        </dl>
    @endif
</section>
