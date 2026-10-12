@props(['project' => null, 'accent' => ['dark' => null, 'light' => null]])
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
@endphp
{{--
    data-motion is how an element opts into the registry. The registry scans
    for these names and dynamically imports ONLY the modules a page actually
    uses — the journal downloads neither of these.

    Both names are listed here and nothing is hidden by their presence: every
    initial state lives in motion.css behind the `.motion` class, which the
    server never writes.
--}}
<section @class(['hero', 'hero--'.$orientation, 'hero--empty' => $image === null])
         data-motion="hero-develop parallax rule"
         aria-labelledby="hero-title"
         style="--wordmark-chars: {{ mb_strlen($wordmark) }};
                @if ($accent['dark']) --hero-accent-dark: {{ $accent['dark'] }}; @endif
                @if ($accent['light']) --hero-accent-light: {{ $accent['light'] }}; @endif">

    {{--
        One heading, one text node, the studio name in plain text. Not an
        image, not split across per-letter spans: it is announced once, it
        copies and pastes as one word, and it is the page's only h1.

        The photograph occludes its middle by sitting above it in the stacking
        order. Nothing about the word itself is fragmented to achieve that.
    --}}
    <h1 class="hero__wordmark" id="hero-title">{{ $wordmark }}</h1>

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
                {{ $project->title }}
                <span class="hero__link-rule" aria-hidden="true"></span>
            </a>
        </div>

        {{-- Metadata as a rail rather than a row under the title. --}}
        <dl class="hero__rail">
            @if ($project->year)
                <div><dt>{{ Settings::get('rail.year') }}</dt><dd>{{ $project->year }}</dd></div>
            @endif
            @if ($project->location)
                <div><dt>{{ Settings::get('rail.place') }}</dt><dd>{{ $project->location }}</dd></div>
            @endif
            @if ($project->client)
                <div><dt>{{ Settings::get('rail.client') }}</dt><dd>{{ $project->client }}</dd></div>
            @endif
            @if ($project->categories->isNotEmpty())
                <div><dt>{{ Settings::get('rail.work') }}</dt><dd>{{ $project->categories->pluck('name')->implode(', ') }}</dd></div>
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
