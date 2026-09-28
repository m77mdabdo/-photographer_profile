@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo" :structured-data="$structuredData">
    <section class="offer" aria-labelledby="offer-title">
        <header class="offer__header">
            <p class="offer__eyebrow">{{ Settings::get('nav.services') }}</p>

            <h1 class="offer__title" id="offer-title">{{ Settings::get('services.heading') }}</h1>

            @if ($standfirst = Settings::get('services.standfirst'))
                <p class="offer__standfirst">{{ $standfirst }}</p>
            @endif
        </header>

        @if ($services->isEmpty())
            {{-- An empty state that reads as a decision rather than a fault. --}}
            <div class="work-empty">
                <p class="work-empty__line">{{ Settings::get('services.empty_line') }}</p>
                <p class="work-empty__note">{{ Settings::get('services.empty_note') }}</p>
                <a class="work-empty__back" href="{{ route('contact.show') }}">{{ Settings::get('closing.cta') }}</a>
            </div>
        @else
            <ol class="offers" role="list">
                @foreach ($services as $service)
                    <li class="offer-card">
                        <article>
                            @if ($service->cover)
                                <a class="offer-card__frame"
                                   href="{{ route('services.show', $service) }}"
                                   tabindex="-1"
                                   aria-hidden="true">
                                    {{-- The first cover is the LCP here; the rest wait. --}}
                                    <x-picture :media="$service->cover"
                                               sizes="(max-width: 900px) 100vw, 30vw"
                                               :priority="$loop->first"
                                               img-class="offer-card__image" />
                                </a>
                            @endif

                            <div class="offer-card__body">
                                <h2 class="offer-card__name">
                                    <a href="{{ route('services.show', $service) }}">{{ $service->name }}</a>
                                </h2>

                                @if ($summary = $service->summaryText(160))
                                    <p class="offer-card__summary">{{ $summary }}</p>
                                @endif

                                {{--
                                    The price only when one is held. A studio
                                    that quotes on request says nothing here
                                    rather than "POA", which is a way of saying
                                    "we will not tell you".
                                --}}
                                @if ($price = $service->priceFrom())
                                    <p class="offer-card__price">
                                        {{ Settings::get('services.price_prefix') }} {{ $price }}
                                        @if ($service->price_note)
                                            <span class="offer-card__price-note">{{ $service->price_note }}</span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif

        <a class="offer__cta" href="{{ route('contact.show') }}">
            {{ Settings::get('services.cta') }}
            <span class="offer__cta-rule" aria-hidden="true"></span>
        </a>
    </section>
</x-layouts::public>
