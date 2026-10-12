@props(['layout'])
@if ($layout !== [])
    {{--
        data-motion="reveal" opts this section into the shared reveal module.
    
        It hides NOTHING by itself: the initial state lives in motion.css behind
        the `.motion` class, and the module adds a `data-reveal-pending` attribute
        only after it has confirmed it can both observe and reveal. With JavaScript
        off, or reduced motion set, or the module failing to load, this attribute
        is inert.
    --}}
    <section class="works" data-motion="reveal" aria-labelledby="works-title">
        <header class="works__header">
            <h2 class="works__title" id="works-title">{{ \App\Support\SiteCopy::get('home.selected_heading') }}</h2>
            <p class="works__count">{{ count($layout) }} {{ Str::plural('set', count($layout)) }}</p>
        </header>

        {{--
            Positions come from EditorialLayout, which reads each cover's
            orientation and what preceded it. Nothing here cycles — the page
            composes differently when the photographs do.
        --}}
        <ol class="works__list" role="list">
            @foreach ($layout as $index => $placement)
                @php($project = $placement['project'])
                <li data-reveal-item class="works__item @if ($placement['feature']) works__item--feature @endif"
                    style="--span: {{ $placement['span'] }}; --start: {{ $placement['start'] }}; --drop: {{ $placement['drop'] }};"
                    data-orientation="{{ $placement['orientation'] }}">
                    <article class="work">
                        <a class="work__link" href="{{ route('projects.show', $project) }}">
                            @if ($project->cover)
                                <span class="work__frame">
                                    <x-picture :media="$project->cover"
                                               sizes="(max-width: 900px) 100vw, {{ round($placement['span'] / 12 * 100) }}vw"
                                               img-class="work__image" />
                                </span>
                            @endif

                            <span class="work__caption">
                                <span class="work__index" aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="work__name">{{ $project->title }}</span>

                                <span class="work__meta">
                                    @if ($project->categories->isNotEmpty())
                                        {{ $project->categories->pluck('name')->implode(' · ') }}
                                    @endif
                                    @if ($project->year) <span class="work__year">{{ $project->year }}</span> @endif
                                </span>
                            </span>
                        </a>
                    </article>
                </li>
            @endforeach
        </ol>
    </section>
@endif
