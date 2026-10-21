<x-layouts::public :seo="$seo" :structured-data="$structuredData ?? null">
@push('head')
    <x-page-transition />
@endpush
    @if ($preview)
        <p class="preview-banner" role="status">
            {{ \App\Support\SiteCopy::get('project.preview_warning') }}
        </p>
    @endif

    <article class="project">
        {{--
            Task 38 Part F. Three of this page's own elements are named for
            the page transition, so it animates the destination rather than
            anything invented: the label is the project's categories, the
            rule draws in the accent its photograph gives (F3, F4), and the
            lead picture is the one unmasked. With no transition they are
            simply a label and a rule.
        --}}
        <header class="project__header"
                style="@if ($accent['dark']) --project-accent-dark: {{ $accent['dark'] }}; @endif @if ($accent['light']) --project-accent-light: {{ $accent['light'] }}; @endif">
            @if ($project->categories->isNotEmpty())
                <p class="project__label" style="view-transition-name: project-label"><x-t-join :items="$project->categories" field="name" separator="comma" /></p>
            @endif
            <h1 class="project__title"><x-t :of="$project" field="title" /></h1>
            <span class="project__rule" aria-hidden="true" style="view-transition-name: project-rule"></span>

            @if ($project->subtitle)
                <p class="project__subtitle"><x-t :of="$project" field="subtitle" /></p>
            @endif

            <dl class="project__facts">
                @if ($project->year)
                    <dt>{{ \App\Support\SiteCopy::get('project.year_label') }}</dt><dd>{{ $project->year }}</dd>
                @endif
                @if ($project->client)
                    <dt>{{ \App\Support\SiteCopy::get('project.client_label') }}</dt><dd><x-t :of="$project" field="client" /></dd>
                @endif
                @if ($project->location)
                    <dt>{{ \App\Support\SiteCopy::get('project.location_label') }}</dt><dd><x-t :of="$project" field="location" /></dd>
                @endif
                @if ($project->categories->isNotEmpty())
                    <dt>{{ \App\Support\SiteCopy::get('project.categories_label') }}</dt>
                    <dd><x-t-join :items="$project->categories" field="name" separator="comma" /></dd>
                @endif
            </dl>

            {{--
                Task 38 E1: the cover's camera data, or nothing at all. The
                cover and not the hero, because the cover is already loaded
                for this page and the hero is not: reading it cost a query on
                every project page (WorkIndexTest pins the count).
            --}}
            <x-spec :media="$project->cover" />

            @if ($project->excerpt)
                <p class="project__excerpt"><x-t :of="$project" field="excerpt" /></p>
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
            {{-- Task 41 5.1: a quotation is a statement unless the block before it was. --}}
            @php($previousWasStatement = false)
            @foreach ($project->blocks as $block)
                @php($isStatement = $block->type === \App\Enums\BlockType::Quote && ! $previousWasStatement)
                @php($previousWasStatement = $isStatement)
                @if ($block->id === $leadBlockId)
                    <div class="project__lead" style="view-transition-name: project-lead">
                        <x-block :block="$block" :credits="$project->credits" :lead="true" />
                    </div>
                @else
                    <x-block :block="$block" :credits="$project->credits" :lead="false" :statement="$isStatement" />
                @endif
            @endforeach
        </div>

        @if ($project->credits->isNotEmpty() && ! $project->blocks->contains(fn ($b) => $b->type === \App\Enums\BlockType::Credits))
            <section class="project__credits">
                <h2>{{ \App\Support\SiteCopy::get('project.credits_label') }}</h2>
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
