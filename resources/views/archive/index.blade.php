{{--
    THE ARCHIVE GETS NO MOTION. A deliberate decision, not an oversight.

    It is the heaviest page on the site: thirty-six frames to a page, every one
    a real photograph, and on a phone that is already the most expensive thing
    this site asks a device to do. A staggered reveal would add thirty-six
    tweens and thirty-six will-change layers on top of that, to decorate a page
    whose entire value is scanning density — a contact sheet works because you
    can take it in at once, and revealing it in sequence actively fights that.

    It also has no hero, no single focal image and no narrative order, so there
    is nothing here for a sequence to be ABOUT.

    So it carries no staggered reveal. (Task 41's dissolve is a different
    thing — one canvas, no per-frame tweens — and does run here, as on every
    page: photographs below the fold arrive under it.)
--}}
<x-layouts::public :seo="$seo">
    <section class="sheet-page" aria-labelledby="archive-title">
        <header class="sheet-page__header">
            <p class="sheet-page__eyebrow">{{ \App\Support\SiteCopy::get('nav.archive') }}</p>

            <h1 class="sheet-page__title" id="archive-title">
                {{ \App\Support\SiteCopy::get('archive.heading') }}
            </h1>

            <p class="sheet-page__count">
                {{ \App\Support\PagerUnits::label('frame', $frames->total()) }}
                @if ($frames->hasPages())
                    <span class="sheet-page__roll">{{ \App\Support\SiteCopy::line('archive.roll_position', ['current' => $frames->currentPage(), 'total' => $frames->lastPage()]) }}</span>
                @endif
            </p>
        </header>

        {{--
            Filters. Query parameters on real links, server-rendered, no
            JavaScript. Each control is an anchor to a complete address, so
            every combination is bookmarkable and works with scripting off.
        --}}
        <nav class="sheet-filter" aria-label="{{ \App\Support\SiteCopy::get('archive.filter_label') }}">
            <div class="sheet-filter__group">
                <span class="sheet-filter__legend" id="filter-category">{{ \App\Support\SiteCopy::get('archive.series_label') }}</span>
                <ul class="sheet-filter__options" role="list" aria-labelledby="filter-category">
                    <li>
                        <a href="{{ route('archive.index', array_filter(['year' => $year])) }}"
                           @class(['sheet-filter__option', 'is-current' => $category === null])
                           @if ($category === null) aria-current="true" @endif>{{ \App\Support\SiteCopy::get('archive.filter_all') }}</a>
                    </li>
                    @foreach ($categories as $option)
                        <li>
                            <a href="{{ route('archive.index', array_filter(['category' => $option->slug, 'year' => $year])) }}"
                               @class(['sheet-filter__option', 'is-current' => $category?->is($option)])
                               @if ($category?->is($option)) aria-current="true" @endif><x-t :of="$option" field="name" /></a>
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($years !== [])
                <div class="sheet-filter__group">
                    <span class="sheet-filter__legend" id="filter-year">{{ \App\Support\SiteCopy::get('archive.year_label') }}</span>
                    <ul class="sheet-filter__options" role="list" aria-labelledby="filter-year">
                        <li>
                            <a href="{{ route('archive.index', array_filter(['category' => $category?->slug])) }}"
                               @class(['sheet-filter__option', 'is-current' => $year === null])
                               @if ($year === null) aria-current="true" @endif>{{ \App\Support\SiteCopy::get('archive.filter_all') }}</a>
                        </li>
                        @foreach ($years as $option)
                            <li>
                                <a href="{{ route('archive.index', array_filter(['category' => $category?->slug, 'year' => $option])) }}"
                                   @class(['sheet-filter__option', 'is-current' => $year === $option])
                                   @if ($year === $option) aria-current="true" @endif>{{ $option }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </nav>

        @if ($frames->isEmpty())
            <div class="work-empty">
                <p class="work-empty__line">{{ \App\Support\SiteCopy::get('archive.empty') }}</p>
                <p class="work-empty__note">{{ \App\Support\SiteCopy::get('archive.empty_note') }}</p>
                <a class="work-empty__back" href="{{ route('archive.index') }}">{{ \App\Support\SiteCopy::get('archive.see_sheet') }}</a>
            </div>
        @else
            {{--
                THE SHEET.

                Not a grid of equal cards. Every frame keeps its own proportion
                and every frame in a row shares a height, exactly as negatives
                sit on a real contact sheet: the 6x7 frames are wider than the
                35mm ones and the strip still lines up.

                That is done with flex alone — each frame's basis and grow are
                both proportional to its aspect ratio, so a row distributes
                width in proportion and settles at a common height. No
                JavaScript measures anything.

                Each frame carries its caption BENEATH IT, always. See the
                stylesheet for why that is not a hover.
            --}}
            <ol class="sheet-strip" role="list">
                @foreach ($frames as $index => $frame)
                    @php
                        $owner = $owners->get($frame->id);
                        $ratio = ($frame->width && $frame->height) ? $frame->width / $frame->height : 1.5;
                        $number = $frames->firstItem() + $index;
                        // The first row is above the fold at every width we support.
                        $eager = $index < 6;
                    @endphp
                    <li class="sheet-frame" style="--ar: {{ round($ratio, 4) }};">
                        @if ($owner)
                            <a class="sheet-frame__link" href="{{ route('projects.show', $owner) }}">
                        @else
                            <div class="sheet-frame__link">
                        @endif

                        <span class="sheet-frame__window">
                            <x-picture :media="$frame"
                                       sizes="(max-width: 700px) 45vw, (max-width: 1100px) 24vw, 17vw"
                                       :priority="$index === 0"
                                       :eager="$eager"
                                       img-class="sheet-frame__image" />
                        </span>

                        {{--
                            The caption is markup, not a tooltip and not a
                            :hover rule. It is here on a touch screen, here for
                            a keyboard, here in the page source, and here when
                            the stylesheet fails.
                        --}}
                        <span class="sheet-frame__caption">
                            <span class="sheet-frame__number" aria-hidden="true">{{ str_pad((string) $number, 3, '0', STR_PAD_LEFT) }}</span>
                            <span class="sheet-frame__series">{{ $owner?->title ?? 'Unattached' }}</span>
                            <span class="sheet-frame__detail">
                                @if ($owner?->year)<span class="sheet-frame__year">{{ $owner->year }}</span>@endif
                                <span class="sheet-frame__size">{{ $frame->width }}&times;{{ $frame->height }}</span>
                            </span>
                        </span>

                        @if ($owner)
                            </a>
                        @else
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>

            {{ $frames->links('pagination.editorial', ['unit' => 'frame', 'ariaLabelKey' => 'shared.pagination_label']) }}
        @endif
    </section>
</x-layouts::public>
