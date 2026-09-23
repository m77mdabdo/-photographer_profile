@props(['project' => null, 'accent' => ['dark' => null, 'light' => null]])
@php
    $image = $project?->hero ?? $project?->cover;

    // The column the photograph occupies is a property of the photograph.
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
        One heading, one text node, the studio name in plain text. Not an
        image, not split across per-letter spans: it is announced once, it
        copies and pastes as one word, and it is the page's only h1.

        The photograph occludes its middle by sitting above it in the stacking
        order. Nothing about the word itself is fragmented to achieve that.
    --}}
    <h1 class="hero__wordmark" id="hero-title">{{ $wordmark }}</h1>

    @if ($image)
        <div class="hero__plate">
            <x-picture :media="$image"
                       sizes="(max-width: 900px) 100vw, 60vw"
                       :priority="true"
                       img-class="hero__image" />
        </div>
    @endif

    @if ($project)
        {{--
            The supporting matter lives in the space the plate leaves, not
            beneath the composition. Everything here comes from the record.
        --}}
        <div class="hero__aside">
            @if ($project->excerpt)
                <p class="hero__statement">{{ $project->excerpt }}</p>
            @elseif ($project->subtitle)
                <p class="hero__statement">{{ $project->subtitle }}</p>
            @endif

            <a class="hero__link" href="{{ route('projects.show', $project) }}">
                {{ $project->title }}
                <span class="hero__link-rule" aria-hidden="true"></span>
            </a>
        </div>

        {{-- Metadata as a vertical rail rather than a row under the title. --}}
        <dl class="hero__rail">
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
    @endif
</section>
