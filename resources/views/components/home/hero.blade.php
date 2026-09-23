@props(['project' => null])
@php($image = $project?->hero ?? $project?->cover)
<section class="hero" aria-labelledby="hero-title">
    @if ($image)
        <div class="hero__frame">
            {{--
                The LCP element. priority => fetchpriority="high", decoding
                sync, and no loading attribute at all — never lazy.
            --}}
            <x-picture :media="$image"
                       sizes="100vw"
                       :priority="true"
                       img-class="hero__image" />
        </div>
    @endif

    <div class="hero__inner">
        @if ($project)
            <p class="hero__eyebrow">
                @if ($project->categories->isNotEmpty())
                    {{ $project->categories->pluck('name')->implode(' · ') }}
                @else
                    Selected work
                @endif
            </p>

            <h1 class="hero__title" id="hero-title">{{ $project->title }}</h1>

            @if ($project->subtitle)
                <p class="hero__subtitle">{{ $project->subtitle }}</p>
            @endif

            <p class="hero__meta">
                @if ($project->year)<span>{{ $project->year }}</span>@endif
                @if ($project->location)<span>{{ $project->location }}</span>@endif
                @if ($project->client)<span>{{ $project->client }}</span>@endif
            </p>

            <a class="hero__link" href="{{ route('projects.show', $project) }}">
                View the series
                <span class="hero__link-rule" aria-hidden="true"></span>
            </a>
        @else
            <h1 class="hero__title" id="hero-title">{{ config('seo.site_name') }}</h1>
            <p class="hero__subtitle">Photography, in series.</p>
        @endif
    </div>
</section>
