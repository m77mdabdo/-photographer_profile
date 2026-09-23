@props(['project' => null])
@if ($project)
    @php($image = $project->hero ?? $project->cover)
    <section class="story" aria-labelledby="story-title">
        @if ($image)
            <div class="story__frame">
                <x-picture :media="$image" sizes="100vw" img-class="story__image" />
            </div>
        @endif

        <div class="story__inner">
            <p class="story__eyebrow">At length</p>
            <h2 class="story__title" id="story-title">{{ $project->title }}</h2>

            @if ($project->excerpt)
                <p class="story__excerpt">{{ $project->excerpt }}</p>
            @endif

            <a class="story__link" href="{{ route('projects.show', $project) }}">
                Read the story
                <span class="story__link-rule" aria-hidden="true"></span>
            </a>
        </div>
    </section>
@endif
