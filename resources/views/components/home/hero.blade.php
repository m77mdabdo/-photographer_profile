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
<section @class(['hero', 'hero--'.$orientation, 'hero--empty' => $image === null])
         aria-labelledby="hero-title"
         style="--wordmark-chars: {{ mb_strlen($wordmark) }};
                @if ($accent['dark']) --hero-accent-dark: {{ $accent['dark'] }}; @endif
                @if ($accent['light']) --hero-accent-light: {{ $accent['light'] }}; @endif">

    {{--
        One heading, one text node. The two lines are produced by constraining
        the measure and letting the word break — a layout decision, not a
        markup one. A screen reader announces “{{ $wordmark }}” once, and it
        copies and pastes whole.
    --}}
    <h1 class="hero__wordmark" id="hero-title">{{ $wordmark }}</h1>

    @if ($image)
        {{--
            The plate is opaque and hard-edged, so the readability guarantee is
            structural: a letter is either fully on the page ground or fully
            behind the picture. There is no scrim anywhere in this component,
            and a contrast problem is never solved by adding one.

            It is also a full-height slab that runs off one screen edge, so the
            photograph never shows a second edge and reads as larger than the
            page.
        --}}
        <div class="hero__plate">
            <x-picture :media="$image"
                       sizes="(max-width: 900px) 70vw, 60vw"
                       :priority="true"
                       img-class="hero__image" />
        </div>
    @endif

    @if ($project)
        {{--
            Everything textual lives in one continuous column beneath the
            wordmark. The silhouette is a single vertical stack of words
            against a mass of photograph, so splitting the text across the
            composition would fight it.
        --}}
        <div class="hero__column">
            @if ($project->excerpt)
                <p class="hero__statement">{{ $project->excerpt }}</p>
            @elseif ($project->subtitle)
                <p class="hero__statement">{{ $project->subtitle }}</p>
            @endif

            <a class="hero__link" href="{{ route('projects.show', $project) }}">
                {{ $project->title }}
                <span class="hero__link-rule" aria-hidden="true"></span>
            </a>

            <dl class="hero__meta">
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
