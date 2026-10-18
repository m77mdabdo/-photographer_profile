@props(['project' => null])
<section class="intro" aria-labelledby="intro-title">
    <h2 class="sr-only" id="intro-title">{{ \App\Support\SiteCopy::get('home.introduction_label') }}</h2>

    <p class="intro__statement">
        @if ($project?->excerpt)
            <x-t :of="$project" field="excerpt" />
        @else
            {{ \App\Support\SiteCopy::get('home.introduction_text') }}
        @endif
    </p>
</section>
