<x-layouts::public :seo="$seo" :structured-data="$structuredData ?? null">
    @if ($preview)
        <p class="preview-banner" role="status">
            Preview of an unpublished project. This link expires and is not indexed.
        </p>
    @endif

    <article class="project">
        <header class="project__header">
            <h1 class="project__title">{{ $project->title }}</h1>

            @if ($project->subtitle)
                <p class="project__subtitle">{{ $project->subtitle }}</p>
            @endif

            <dl class="project__facts">
                @if ($project->year)
                    <dt>Year</dt><dd>{{ $project->year }}</dd>
                @endif
                @if ($project->client)
                    <dt>Client</dt><dd>{{ $project->client }}</dd>
                @endif
                @if ($project->location)
                    <dt>Location</dt><dd>{{ $project->location }}</dd>
                @endif
                @if ($project->categories->isNotEmpty())
                    <dt>Categories</dt>
                    <dd>{{ $project->categories->pluck('name')->implode(', ') }}</dd>
                @endif
            </dl>

            @if ($project->excerpt)
                <p class="project__excerpt">{{ $project->excerpt }}</p>
            @endif
        </header>

        {{--
            Blocks render in stored order. Every image was eager-loaded by the
            controller, so nothing in this loop touches the database.

            THE LCP. A story may well open with a paragraph or a pull quote, so
            the largest contentful paint is the first block that actually holds
            a picture — not the first block. Marking block zero regardless
            would prioritise nothing on any story that opens with words, and
            leave the real opening image lazy.
        --}}
        @php($leadBlockId = $project->blocks->first(fn ($b) => $b->blockMedia->isNotEmpty())?->id)

        <div class="project__story">
            @foreach ($project->blocks as $block)
                <x-block :block="$block" :credits="$project->credits"
                         :lead="$block->id === $leadBlockId" />
            @endforeach
        </div>

        @if ($project->credits->isNotEmpty() && ! $project->blocks->contains(fn ($b) => $b->type === \App\Enums\BlockType::Credits))
            <section class="project__credits">
                <h2>Credits</h2>
                <dl>
                    @foreach ($project->credits as $credit)
                        <dt>{{ $credit->role }}</dt>
                        <dd>{{ $credit->name }}</dd>
                    @endforeach
                </dl>
            </section>
        @endif
    </article>
</x-layouts::public>
