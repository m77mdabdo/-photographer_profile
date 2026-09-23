<x-layouts::public :seo="$seo">
    {{-- One switch, in config/site.php. Deleting the loser is one commit. --}}
    @if (config('site.hero_variant') === 'b')
        <x-home.hero-b :project="$hero" :accent="$heroAccent" />
    @else
        <x-home.hero :project="$hero" :accent="$heroAccent" />
    @endif

    <x-home.client-marquee :clients="$clients" />

    <x-home.introduction :project="$hero" />

    <x-home.selected-works :layout="$layout" />

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
