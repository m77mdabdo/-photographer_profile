@props(['project' => null, 'accent' => ['dark' => null, 'light' => null]])
{{--
    $split: where an Arabic name is cut by the plate, from App\Support\HeroSplit.
    Null means the name has no single word boundary, and the hero takes the
    vertical interlock (.hero--vertical) instead.
--}}
@php
    use App\Support\Settings;

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

    /*
     * Every string below is a row in `settings` or a column on the record.
     * There is no copy in this file — a studio name, a statement or a stage
     * label written here would be a string the client cannot change.
     */
    $wordmark = config('seo.site_name');       // settings overlay this key
    $sequence = Settings::get('hero.sequence_label');
    $statement = Settings::get('site.statement');

    $attribution = array_values(array_filter([
        Settings::get('attribution.name'),
        Settings::get('attribution.role'),
        Settings::get('attribution.city'),
    ]));

    /*
     * See App\Support\HeroStages: the keys are literal so they can be proven
     * read, and an empty stage is dropped rather than numbered blankly.
     */
    $stages = \App\Support\HeroStages::all();

    $place = array_values(array_filter([
        Settings::get('site.location'),
        Settings::get('site.disciplines'),
    ]));

    /*
     * Real numbers or nothing. A frame with no camera data renders no
     * exposure line at all rather than a row of dashes — see ExposureLine.
     */
    $exposure = $image?->exposureLine();

    $split = \App\Support\Locale::direction() === 'rtl'
        ? \App\Support\HeroSplit::for((string) $wordmark, $orientation)
        : null;

    $composition = match (true) {
        \App\Support\Locale::direction() !== 'rtl' => null,
        $split !== null => 'hero--split',
        default => 'hero--vertical',
    };
@endphp
{{--
    The hero carries no motion attribute. Task 42 removed the intro that
    clip-path animated this plate (it held the LCP image and ran only on a
    session's first page); the only movement left is the desktop parallax,
    which the arrive layer finds by class and leaves at identity at rest.
--}}
<section @class(['hero', 'hero--'.$orientation, 'hero--empty' => $image === null, $composition => $composition !== null])
         aria-labelledby="hero-title"
         style="--wordmark-chars: {{ mb_strlen($wordmark) }};
                @if ($split) @foreach ($split as $k => $v) --{{ $k }}: {{ $v }}; @endforeach @endif
                @if ($accent['dark']) --hero-accent-dark: {{ $accent['dark'] }}; @endif
                @if ($accent['light']) --hero-accent-light: {{ $accent['light'] }}; @endif">

    {{--
        One heading, one text node, the studio name in plain text. Not an
        image, not split across per-letter spans: it is announced once, it
        copies and pastes as one word, and it is the page's only h1.

        The photograph occludes its middle by sitting above it in the stacking
        order. Nothing about the word itself is fragmented to achieve that.
    --}}
    {{--
        THE WRAPPER EXISTS FOR ONE REASON AND IT IS NOT STYLING. Task 38 A1.

        The Arabic composition rotates the wordmark's block ninety degrees.
        That rotation cannot live on the <h1> itself: hero-develop animates
        the wordmark with GSAP's yPercent, which writes the whole transform
        matrix, so a `transform: rotate(-90deg)` in the stylesheet is erased
        the moment the intro timeline touches it — measured as
        `matrix(1, 0, 0, 1, 0, 15.517)`, a pure translation, with the word
        still lying horizontally across the plate.

        Moving the rotation into the timeline would make the composition
        depend on JavaScript, which the brief forbids and which would leave an
        Arabic visitor with no script looking at the broken occlusion.

        So the block that rotates is this element, and the <h1> inside it is
        untouched: still one text node, still one heading, still selectable
        and copy-pasteable as one word, still announced once. In English this
        wrapper has `display: contents` and therefore no box at all — the <h1>
        remains a direct grid item of .hero and the English geometry is
        byte-identical, which tests/Support/hero-interlock.mjs and the A3
        measurement both check.
    --}}
    <div class="hero__wordmark-block">
        <h1 class="hero__wordmark" id="hero-title">{{ $wordmark }}</h1>
    </div>

    {{--
        The technical block. The sequence label always stands; the exposure
        line appears only when the frame really carries one.
    --}}
    @if ($sequence || $exposure)
        <div class="hero__technical">
            @if ($sequence)
                <p class="hero__sequence">{{ $sequence }}</p>
            @endif

            @if ($exposure)
                <p class="hero__exposure">{{ $exposure->compact() }}</p>
            @endif
        </div>
    @endif

    @if ($image)
        <div class="hero__plate">
            <x-picture :media="$image"
                       sizes="(max-width: 900px) 100vw, 60vw"
                       :priority="true"
                       img-class="hero__image" />
        </div>
    @endif

    {{--
        The statement and the attribution.

        These are layered BENEATH the wordmark, so the giant letters cut across
        them. That is a stacking decision only: this block sits here, after the
        heading and before the project link, which is the order a screen reader
        and the tab key both follow. Nothing is reordered to achieve the visual
        effect, and nothing is hidden from assistive technology to tidy it up.
    --}}
    @if ($statement || $attribution !== [])
        <div class="hero__voice">
            @if ($statement)
                <p class="hero__statement">{{ $statement }}</p>
            @endif

            @if ($attribution !== [])
                <p class="hero__attribution">{{ implode(' — ', $attribution) }}</p>
            @endif
        </div>
    @endif

    @if ($project)
        {{-- The one primary action, carrying the colour taken from the photograph. --}}
        <div class="hero__aside">
            <a class="hero__link" href="{{ route('projects.show', $project) }}">
                <x-t :of="$project" field="title" />
                <span class="hero__link-rule" aria-hidden="true"></span>
            </a>
        </div>

        {{-- Metadata as a rail rather than a row under the title. --}}
        <dl class="hero__rail">
            @if ($project->year)
                <div><dt>{{ Settings::get('rail.year') }}</dt><dd>{{ $project->year }}</dd></div>
            @endif
            @if ($project->location)
                <div><dt>{{ Settings::get('rail.place') }}</dt><dd><x-t :of="$project" field="location" /></dd></div>
            @endif
            @if ($project->client)
                <div><dt>{{ Settings::get('rail.client') }}</dt><dd><x-t :of="$project" field="client" /></dd></div>
            @endif
            @if ($project->categories->isNotEmpty())
                <div><dt>{{ Settings::get('rail.work') }}</dt><dd><x-t-join :items="$project->categories" field="name" separator="comma" /></dd></div>
            @endif
        </dl>
    @endif

    {{--
        The stage rail. Markers, not links: they name the sequence the page
        below follows, and two of the four sections they describe only exist
        when there is content for them — a marker that sometimes points
        nowhere is worse than one that never pretends to.
    --}}
    @if ($stages !== [])
        <ol class="hero__stages" role="list">
            @foreach ($stages as $stage)
                <li @class(['hero__stage', 'hero__stage--first' => $loop->first])>
                    <span class="hero__stage-rule" aria-hidden="true"></span>
                    <span class="hero__stage-label">{{ $stage['label'] }}</span>
                    <span class="hero__stage-number">{{ $stage['number'] }}</span>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($place !== [])
        <p class="hero__place">
            @foreach ($place as $part)
                <span>{{ $part }}</span>
            @endforeach
        </p>
    @endif
</section>
