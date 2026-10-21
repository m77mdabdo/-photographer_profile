@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo">
    <section class="studio" aria-labelledby="studio-title">
        <header class="studio__header">
            <p class="studio__eyebrow">{{ Settings::get('nav.about') }}</p>
            <h1 class="studio__title" id="studio-title">{{ Settings::get('about.heading') }}</h1>
        </header>

        {{--
            The portrait and the opening text share one band, so the page
            reads as a spread rather than a picture with a caption a screen
            below it. On a tall portrait frame the alternative leaves half the
            page empty and pushes every word under the fold.
        --}}
        <div class="studio__composition">
            {{--
                THE PORTRAIT IS THE LCP.
                Priority, decoded synchronously, never lazy. Everything else
                on this page is words, so nothing competes for it.
            --}}
            @if ($portrait)
                <figure class="studio__portrait">
                    <x-picture :media="$portrait"
                               sizes="(max-width: 900px) 100vw, 42vw"
                               :priority="true"
                               img-class="studio__portrait-image" />
                </figure>
            @endif

            <div class="studio__body">
                @if ($biography = Settings::get('about.biography'))
                    <div class="studio__prose">
                        {{--
                            TASK 36b — the biography is Arabic prose that names
                            two Latin publications, and a settings value does
                            not go through <x-t>. isolateForeign wraps the
                            foreign runs so they keep their own direction; it
                            returns the value untouched on an English page.
                        --}}
                        @foreach (preg_split('/\R\s*\R/', trim($biography)) as $paragraph)
                            <p>{{ \App\Support\Fallback::isolateForeign($paragraph) }}</p>
                        @endforeach
                    </div>
                @endif

                <dl class="studio__facts">
                    @if ($location = Settings::get('site.location'))
                        <div><dt>{{ Settings::get('rail.place') }}</dt><dd>{{ $location }}</dd></div>
                    @endif
                    @if ($disciplines = Settings::get('site.disciplines'))
                        <div><dt>{{ Settings::get('rail.work') }}</dt><dd>{{ $disciplines }}</dd></div>
                    @endif
                    @php
                        $attribution = array_values(array_filter([
                            Settings::get('attribution.name'),
                            Settings::get('attribution.role'),
                        ]));
                    @endphp
                    @if ($attribution !== [])
                        <div><dt>{{ Settings::get('about.studio_label') }}</dt><dd>{{ implode(' — ', $attribution) }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>

        @if ($approach = Settings::get('about.approach'))
            <section class="studio__approach" aria-labelledby="approach-title">
                <h2 class="studio__approach-title" id="approach-title">
                    {{ Settings::get('about.approach_heading') }}
                </h2>

                <div class="studio__prose">
                    {{-- Task 41 5.2: the approach opens with a statement — its
                         first paragraph, from the setting; the rest reads plainly. --}}
                    @foreach (preg_split('/\R\s*\R/', trim($approach)) as $paragraph)
                        @if ($loop->first)
                            <x-statement class="studio__statement">{{ $paragraph }}</x-statement>
                        @else
                            <p>{{ $paragraph }}</p>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if ($clients->isNotEmpty())
            <section class="studio__clients" aria-labelledby="clients-title">
                <h2 class="studio__clients-title" id="clients-title">
                    {{ Settings::get('about.clients_heading') }}
                </h2>

                <ul class="studio__clients-list" role="list">
                    @foreach ($clients as $client)
                        <li>
                            @if ($client->url)
                                <a href="{{ $client->url }}" rel="noopener"><x-t :of="$client" field="name" /></a>
                            @else
                                <span><x-t :of="$client" field="name" /></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The route to commissioning, which is what this page is for. --}}
        <a class="studio__cta" href="{{ route('contact.show') }}">
            {{ Settings::get('about.cta') }}
            <span class="studio__cta-rule" aria-hidden="true"></span>
        </a>
    </section>
</x-layouts::public>
