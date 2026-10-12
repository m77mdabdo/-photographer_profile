@props(['media'])
@if ($media->isNotEmpty())
    <section class="sheet" aria-labelledby="sheet-title">
        <header class="sheet__header">
            <h2 class="sheet__title" id="sheet-title">{{ \App\Support\SiteCopy::get('home.archive_heading') }}</h2>
            <p class="sheet__note">{{ \App\Support\SiteCopy::get('home.archive_note') }}</p>
        </header>

        {{--
            A contact sheet: many small frames, even weight, no captions. It
            wraps rather than scrolling sideways, so nothing sits off-screen
            on a phone.
        --}}
        <ul class="sheet__grid" role="list">
            @foreach ($media as $frame)
                <li class="sheet__cell">
                    <x-picture :media="$frame"
                               sizes="(max-width: 700px) 33vw, 16vw"
                               img-class="sheet__image"
                               :decorative="true" />
                </li>
            @endforeach
        </ul>
    </section>
@endif
