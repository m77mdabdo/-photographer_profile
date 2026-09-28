@props(['services'])
@php use App\Support\Settings; @endphp
@if ($services->isNotEmpty())
    {{--
        What the studio does, as a short list.

        Reads from the services table rather than from copy in this file, so
        adding a service in the admin changes the home page. Typographic like
        the journal strip: the home page is already several grids of
        photographs, and a fourth would read as more of the same.
    --}}
    <section class="services-strip" aria-labelledby="services-strip-title">
        <header class="services-strip__header">
            <h2 class="services-strip__title" id="services-strip-title">
                {{ Settings::get('services.home_heading') }}
            </h2>
        </header>

        <ol class="services-strip__list" role="list">
            @foreach ($services as $service)
                <li class="services-strip__item">
                    <h3 class="services-strip__name">
                        <a href="{{ route('services.show', $service) }}">{{ $service->name }}</a>
                    </h3>

                    @if ($summary = $service->summaryText(110))
                        <p class="services-strip__summary">{{ $summary }}</p>
                    @endif

                    @if ($price = $service->priceFrom())
                        <p class="services-strip__price">{{ Settings::get('services.price_prefix') }} {{ $price }}</p>
                    @endif
                </li>
            @endforeach
        </ol>

        <a class="services-strip__cta" href="{{ route('services.index') }}">
            {{ Settings::get('services.home_cta') }}
            <span class="journal-strip__rule" aria-hidden="true"></span>
        </a>
    </section>
@endif
