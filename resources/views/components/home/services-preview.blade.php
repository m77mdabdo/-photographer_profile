@props(['services'])
@php use App\Support\Settings; @endphp
@if ($services->isNotEmpty())
    {{--
        What the studio does, as a numbered grid — Task 38 Part E.

        THE ROWS LIVE IN THE SERVICES TABLE (E2), which is where they already
        lived: one record per row, owner-edited in the panel, ordered by
        sort_order. The numeral is the row's POSITION — 01, 02, … — so adding,
        removing or reordering a service renumbers the grid with nobody
        touching this file, and with five services it reads 01–05 rather than
        being padded to the reference's six. Heading, paragraph and the accent
        label (the price line) are each a field of that record.

        THE ROW ABOVE IT is the section's micro-label row: the heading as a
        mono label on the leading edge, and the link to every service as the
        accent label on the trailing edge — the CTA that used to sit under the
        grid, moved rather than duplicated.

        HIERARCHY IS NOT CARRIED BY CASE (E3). Uppercase is inert in Arabic,
        so the numeral is large, the label is small and secondary, and the
        accent marks the one thing that acts or costs.
    --}}
    <section class="services-strip" data-motion="reveal rule" aria-labelledby="services-strip-title">
        <header class="services-strip__header">
            <h2 class="services-strip__title" id="services-strip-title">
                {{ Settings::get('services.home_heading') }}
            </h2>
            <a class="services-strip__cta" href="{{ route('services.index') }}">
                {{ Settings::get('services.home_cta') }}
            </a>
        </header>
        <ol class="services-strip__list" role="list">
            @foreach ($services as $service)
                <li class="services-strip__item" data-reveal-item>
                    <span class="services-strip__number" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
                    <h3 class="services-strip__name">
                        <a href="{{ route('services.show', $service) }}"><x-t :of="$service" field="name" /></a>
                    </h3>
                    @if ($summary = $service->summaryText(110))
                        <p class="services-strip__summary"{!! \App\Support\Fallback::attributes($service, $service->summaryTextSource()) !!}>{{ $summary }}</p>
                    @endif
                    @if ($price = $service->priceFrom())
                        <p class="services-strip__price">{{ Settings::get('services.price_prefix') }} {{ $price }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
