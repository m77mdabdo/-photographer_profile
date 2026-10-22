@props(['sections'])
{{--
    THE CINEMATIC SEQUENCE — the home page's lighting galleries and featured
    media, in the owner's order (Studio → Cinematic sections).

    Everything here renders FULLY LIT. The dark state, the moving light and
    the scaled entrance are drawn by resources/js/public/cinematic.js, which
    is not loaded under reduced motion and does nothing if it fails: a visitor
    without it sees every photograph as it was made. data-arrive-skip keeps
    Task 41's tiled dissolve off these images — one entrance, not two.
--}}
@if ($sections->isNotEmpty())
<div class="cinematic" data-register="fixed" data-arrive-skip>
    @foreach ($sections as $section)
        @php
            $light = $section->lighting();
            $title = $section->getTranslation('title', null, fallback: true);
            $text = $section->getTranslation('description', null, fallback: true);
            $id = 'cine-'.$section->id;
        @endphp
        <section class="cine cine--{{ $section->type }}"
                 data-cinematic="{{ $section->type }}"
                 data-direction="{{ $light['direction'] }}"
                 data-mode="{{ $light['mode'] }}"
                 data-softness="{{ $light['softness'] }}"
                 data-intensity="{{ $light['intensity'] }}"
                 data-darkness="{{ $light['darkness'] }}"
                 data-pace="{{ $light['pace'] }}"
                 data-overlay="{{ $light['overlay'] }}"
                 data-mobile="{{ $light['mobile'] }}"
                 style="--cine-mobile-width: {{ $light['mobileWidth'] }}vw"
                 data-animate="{{ $light['animate'] }}"
                 @if ($title) aria-labelledby="{{ $id }}" @else aria-label="{{ __('studio.cinematic.type.'.$section->type) }}" @endif>

            @if ($section->type === \App\Models\CinematicSection::TYPE_GALLERY)
                {{--
                    The pin: the stage the spotlight plays on. cinematic.js
                    makes the section tall and this sticky, so scrolling moves
                    the light rather than the page; without it the section is
                    its natural height and every photograph is lit.
                --}}
                <div class="cine__pin">
                @if ($title || $text)
                    <header class="cine__header">
                        @if ($title)
                            <x-statement tag="h2" class="cine__title" id="{{ $id }}">{{ $title }}</x-statement>
                        @endif
                        @if ($text)
                            <p class="cine__text">{{ $text }}</p>
                        @endif
                    </header>
                @endif

                @php($frames = $section->readyItems())
                {{-- dir is the light's direction on screen: the page's own, or the owner's override. --}}
                <ol class="cine__strip" role="list" dir="{{ $light['direction'] }}" style="--cine-count: {{ $frames->count() }}">
                    @foreach ($frames as $i => $media)
                        <li class="cine__panel" data-cine-panel>
                            <x-picture :media="$media"
                                       sizes="(max-width: 899px) 50vw, {{ (int) round(100 / $frames->count()) }}vw"
                                       img-class="cine__image" />
                            <span class="cine__index" aria-hidden="true">{{ sprintf('%02d', $i + 1) }}</span>
                        </li>
                    @endforeach
                </ol>
                </div>
            @else
                <div class="cine__stage">
                    @if ($section->type === \App\Models\CinematicSection::TYPE_IMAGE)
                        <x-picture :media="$section->featured" sizes="100vw" img-class="cine__media" />
                    @else
                        {{--
                            Muted, inline, no preload: nothing downloads until
                            cinematic.js decides it is on screen. `controls`
                            is server-rendered so a visitor without the script
                            (or preferring less motion) can still play it; the
                            script removes them when it takes over playback.
                        --}}
                        <video class="cine__media"
                               muted loop playsinline controls
                               preload="none"
                               @if ($section->poster?->isReady()) poster="{{ $section->poster->url('jpeg', 1920) ?? $section->poster->url('webp', 1920) }}" @endif
                               @if ($title) aria-labelledby="{{ $id }}" @else aria-label="{{ $section->poster?->getTranslation('alt', null, fallback: true) ?? __('studio.cinematic.type.video') }}" @endif
                               data-cine-video>
                            <source src="{{ $section->videoUrl() }}" type="{{ str_ends_with((string) $section->video_path, '.webm') ? 'video/webm' : 'video/mp4' }}">
                        </video>
                    @endif
                </div>

                @if ($title || $text)
                    <div class="cine__caption">
                        @if ($title)
                            <h2 class="cine__headline" id="{{ $id }}" data-cine-line>{{ $title }}</h2>
                        @endif
                        @if ($text)
                            <p class="cine__text" data-cine-line>{{ $text }}</p>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    @endforeach
</div>
@endif
