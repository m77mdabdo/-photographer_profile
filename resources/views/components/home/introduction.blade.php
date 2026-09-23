@props(['project' => null])
<section class="intro" aria-labelledby="intro-title">
    <h2 class="sr-only" id="intro-title">Introduction</h2>

    <p class="intro__statement">
        @if ($project?->excerpt)
            {{ $project->excerpt }}
        @else
            Work made slowly, in series, and shown the same way.
        @endif
    </p>
</section>
