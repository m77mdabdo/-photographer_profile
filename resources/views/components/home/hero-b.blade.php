@props(['project' => null, 'accent' => ['dark' => null, 'light' => null]])
@php
    $image = $project?->hero ?? $project?->cover;

    $orientation = 'landscape';

    if ($image?->width && $image?->height) {
        $ratio = $image->width / $image->height;
        $orientation = match (true) {
            $ratio > 1.05 => 'landscape',
            $ratio < 0.95 => 'portrait',
            default => 'square',
        };
    }

    $wordmark = config('seo.site_name');
@endphp
<section @class(['hero-b', 'hero-b--'.$orientation, 'hero-b--empty' => $image === null])
         aria-labelledby="hero-title"
         style="--wordmark-chars: {{ mb_strlen($wordmark) }};
                @if ($accent['dark']) --hero-accent-dark: {{ $accent['dark'] }}; @endif
                @if ($accent['light']) --hero-accent-light: {{ $accent['light'] }}; @endif">

    {{--
        Still one heading, still one text node. The two lines are produced by
        constraining the measure and letting the word break — a layout
        decision, not a markup one. A screen reader announces “{{ $wordmark }}”
        once, and it copies and pastes whole.
    --}}
    <h1 class="hero-b__wordmark" id="hero-title">{{ $wordmark }}</h1>

    @if ($image)
        {{--
            The plate is opaque and hard-edged, exactly as in variant A, so the
            readability guarantee is structural: a letter is either fully
            hidden behind it or fully on the page ground. It bleeds off the
            screen edge rather than sitting inside the grid.
        --}}
        <div class="hero-b__plate">
            <x-picture :media="$image"
                       sizes="(max-width: 900px) 100vw, 55vw"
                       :priority="true"
                       img-class="hero-b__image" />
        </div>
    @endif

    @if ($project)
        {{--
            Everything textual lives in one continuous left column beneath the
            wordmark, rather than being split across the composition as in A.
            The silhouette here is a single vertical stack of words against a
            mass of photograph, so splitting the text would fight it.
        --}}
        <div class="hero-b__column">
            @if ($project->excerpt)
                <p class="hero-b__statement">{{ $project->excerpt }}</p>
            @elseif ($project->subtitle)
                <p class="hero-b__statement">{{ $project->subtitle }}</p>
            @endif

            <a class="hero-b__link" href="{{ route('projects.show', $project) }}">
                {{ $project->title }}
                <span class="hero-b__link-rule" aria-hidden="true"></span>
            </a>

            <dl class="hero-b__meta">
                @if ($project->year)
                    <div><dt>Year</dt><dd>{{ $project->year }}</dd></div>
                @endif
                @if ($project->location)
                    <div><dt>Place</dt><dd>{{ $project->location }}</dd></div>
                @endif
                @if ($project->client)
                    <div><dt>For</dt><dd>{{ $project->client }}</dd></div>
                @endif
                @if ($project->categories->isNotEmpty())
                    <div><dt>Work</dt><dd>{{ $project->categories->pluck('name')->implode(', ') }}</dd></div>
                @endif
            </dl>
        </div>
    @endif
</section>
