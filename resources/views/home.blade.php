<x-layouts::public :seo="$seo">
@push('head')
    <x-page-transition />
@endpush
    <x-home.hero :project="$hero" :accent="$heroAccent" />

    <x-home.client-marquee :clients="$clients" />

    {{--
        THE REGISTERS ALTERNATE OVER WHAT RENDERS (Task 38 Part C).

        Every section below is conditional on content. Fixed positions for the
        bands broke the first time it was rendered: with no testimonials yet,
        the archive band and the closing band met and read as one long paper
        block. So each section is rendered first, the empty ones are dropped,
        and the first, third, fifth… of those that remain are set in the other register, so the band
        starts straight after the marquee —
        tokens.css says what that means for a visitor in either theme, and
        components/register.css what the band draws.
    --}}
    @php
        $sections = array_values(array_filter(array_map(
            fn (string $tag) => trim(\Illuminate\Support\Facades\Blade::render($tag, [
                'hero' => $hero,
                'layout' => $layout,
                'story' => $story,
                'services' => $services,
                'journal' => $journal,
                'archive' => $archive,
                'testimonials' => $testimonials,
                'cinematic' => $cinematic,
            ])),
            [
                '<x-home.introduction :project="$hero" />',
                '<x-home.selected-works :layout="$layout" />',
                '<x-home.cinematic-sequence :sections="$cinematic" />',
                '<x-home.featured-story :project="$story" />',
                '<x-home.services-preview :services="$services" />',
                '<x-home.journal-preview :articles="$journal" />',
                '<x-home.archive-strip :media="$archive" />',
                '<x-home.testimonials :testimonials="$testimonials" />',
                '<x-home.closing />',
            ],
        ), fn (string $html) => $html !== ''));
    @endphp

    {{--
        The cinematic sequence stays dark whatever its position: a lighting
        gallery in the paper register would be lit before it began. It is
        marked data-register="fixed" and the alternation counts around it.
    --}}
    @php($band = 0)
    @foreach ($sections as $html)
        @if (str_contains($html, 'data-register="fixed"'))
            {!! $html !!}
        @elseif ($band++ % 2 === 0)
            <div class="register register--inverse">{!! $html !!}</div>
        @else
            {!! $html !!}
        @endif
    @endforeach

    @if (! $hero && $selected->isEmpty() && $archive->isEmpty())
        {{-- Nothing published yet: say so plainly rather than showing a shell. --}}
        <section class="nothing-yet">
            <h2>{{ \App\Support\SiteCopy::get('home.empty_heading') }}</h2>
            <p>{{ \App\Support\SiteCopy::get('home.empty_note') }}</p>
        </section>
    @endif
</x-layouts::public>
