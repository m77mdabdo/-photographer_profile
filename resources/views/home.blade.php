<x-layouts::public :seo="$seo">
    <x-home.hero :project="$hero" />

    <x-home.introduction :project="$hero" />

    <x-home.selected-works :projects="$selected" />

    <x-home.featured-story :project="$story" />

    <x-home.archive-strip :media="$archive" />

    <x-home.closing />

    @if (! $hero && $selected->isEmpty() && $archive->isEmpty())
        {{-- Nothing published yet: say so plainly rather than showing a shell. --}}
        <section class="nothing-yet">
            <h2>Nothing published yet</h2>
            <p>Work appears here once a project is published.</p>
        </section>
    @endif
</x-layouts::public>
