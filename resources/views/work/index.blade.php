<x-layouts::public :seo="$seo">
    <section class="work-index" aria-labelledby="work-title">
        <header class="work-index__header">
            <p class="work-index__eyebrow">{{ \App\Support\SiteCopy::get('nav.work') }}</p>

            <h1 class="work-index__title" id="work-title">
                {{ $category?->name ?? \App\Support\SiteCopy::get('work.heading') }}
            </h1>

            @if ($category?->description)
                <p class="work-index__standfirst"><x-t :of="$category" field="description" /></p>
            @endif

            <p class="work-index__count">
                {{ \App\Support\PagerUnits::label('series', $projects->total()) }}
            </p>
        </header>

        {{--
            Category filtering. Plain links to real addresses — each category
            is an indexable landing page, not a state this page can be put
            into, so there is nothing here for JavaScript to do.
        --}}
        @if ($categories->isNotEmpty())
            <nav class="work-filter" aria-label="{{ \App\Support\SiteCopy::get('work.filter_label') }}">
                <a href="{{ route('work.index') }}"
                   @class(['work-filter__option', 'is-current' => $category === null])
                   @if ($category === null) aria-current="page" @endif>
                    {{ \App\Support\SiteCopy::get('work.filter_all') }}<span class="work-filter__count">{{ $projects->total() }}</span>
                </a>

                @foreach ($categories as $option)
                    <a href="{{ route('work.category', $option) }}"
                       @class(['work-filter__option', 'is-current' => $category?->is($option)])
                       @if ($category?->is($option)) aria-current="page" @endif>
                        <x-t :of="$option" field="name" /><span class="work-filter__count">{{ $option->projects_count }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($layout === [])
            {{--
                An empty state that reads as a decision rather than a fault.
                No apology, no spinner, no illustration of an empty box.
            --}}
            <div class="work-empty">
                <p class="work-empty__line">
                    @if ($category !== null)
                        {{ \App\Support\SiteCopy::line('work.empty_filtered', ['category' => $category->name]) }}
                    @else
                        {{ \App\Support\SiteCopy::get('work.empty') }}
                    @endif
                </p>
                <p class="work-empty__note">
                    {{ \App\Support\SiteCopy::get('work.empty_note') }}
                </p>
                @if ($category !== null)
                    <a class="work-empty__back" href="{{ route('work.index') }}">{{ \App\Support\SiteCopy::get('work.see_all') }}</a>
                @endif
            </div>
        @else
            {{--
                Positions come from EditorialLayout, the same planner the home
                page uses: spans, starts and drops derived from each cover's
                orientation and what preceded it. Nothing cycles.

                The plan is made PER PAGE. A page is a signature in a book: it
                opens with a feature and composes on its own terms. Feeding the
                planner a slice that begins halfway through a rhythm would give
                every page after the first an arbitrary opening.
            --}}
            <ol class="works__list works__list--index" role="list">
                @foreach ($layout as $index => $placement)
                    @php($project = $placement['project'])
                    <li class="works__item @if ($placement['feature']) works__item--feature @endif"
                        style="--span: {{ $placement['span'] }}; --start: {{ $placement['start'] }}; --drop: {{ $placement['drop'] }};"
                        data-orientation="{{ $placement['orientation'] }}">
                        <article class="work">
                            <a class="work__link" href="{{ route('projects.show', $project) }}">
                                @if ($project->cover)
                                    <span class="work__frame">
                                        {{-- The first cover is the LCP element on this page. --}}
                                        <x-picture :media="$project->cover"
                                                   sizes="(max-width: 900px) 100vw, {{ round($placement['span'] / 12 * 100) }}vw"
                                                   :priority="$index === 0"
                                                   img-class="work__image" />
                                    </span>
                                @endif

                                <span class="work__caption">
                                    <span class="work__index" aria-hidden="true">{{ str_pad((string) ($projects->firstItem() + $index), 2, '0', STR_PAD_LEFT) }}</span>
                                    <span class="work__name"><x-t :of="$project" field="title" /></span>

                                    <span class="work__meta">
                                        @if ($project->categories->isNotEmpty())
                                            <x-t-join :items="$project->categories" field="name" />
                                        @endif
                                        @if ($project->year) <span class="work__year">{{ $project->year }}</span> @endif
                                    </span>
                                </span>
                            </a>
                        </article>
                    </li>
                @endforeach
            </ol>

            {{ $projects->links('pagination.editorial', ['unit' => 'series', 'ariaLabelKey' => 'shared.pagination_label']) }}
        @endif
    </section>
</x-layouts::public>
