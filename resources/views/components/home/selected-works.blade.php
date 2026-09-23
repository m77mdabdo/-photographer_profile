@props(['projects'])
@if ($projects->isNotEmpty())
    <section class="works" aria-labelledby="works-title">
        <header class="works__header">
            <h2 class="works__title" id="works-title">Selected work</h2>
            <p class="works__count">{{ $projects->count() }} {{ Str::plural('series', $projects->count()) }}</p>
        </header>

        {{--
            Deliberately not a uniform grid. Each item carries its own column
            span and alignment from its index, so the eye moves down the page
            in a rhythm rather than scanning rows of equal cards.
        --}}
        <ol class="works__list" role="list">
            @foreach ($projects as $index => $project)
                <li class="works__item works__item--{{ ($index % 4) + 1 }}">
                    <article class="work">
                        <a class="work__link" href="{{ route('projects.show', $project) }}">
                            @if ($project->cover)
                                <span class="work__frame">
                                    <x-picture :media="$project->cover"
                                               sizes="(max-width: 700px) 100vw, 50vw"
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
