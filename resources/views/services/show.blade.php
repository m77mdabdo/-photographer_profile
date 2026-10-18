@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo" :structured-data="$structuredData">
    <article class="offering">
        <header class="offering__header">
            <p class="offering__eyebrow">
                <a href="{{ route('services.index') }}">{{ Settings::get('nav.services') }}</a>
            </p>

            <h1 class="offering__title"><x-t :of="$service" field="name" /></h1>

            @if ($service->summary)
                <p class="offering__standfirst"><x-t :of="$service" field="summary" /></p>
            @endif

            @if ($price = $service->priceFrom())
                <p class="offering__price">
                    {{ Settings::get('services.price_prefix') }} <strong>{{ $price }}</strong>
                    @if ($service->price_note)
                        <span class="offering__price-note"><x-t :of="$service" field="price_note" /></span>
                    @endif
                </p>
            @endif
        </header>

        @if ($service->cover)
            <figure class="offering__figure">
                {{--
                    The ratio box wraps ONLY the picture. It carries
                    overflow:hidden so a portrait cover is cropped to a
                    consistent frame rather than standing three screens tall —
                    and a figcaption inside that box would be cropped with it,
                    which is how this was wrong for about a minute.
                --}}
                <div class="offering__frame">
                    {{-- The LCP on this page. Priority, never lazy. --}}
                    <x-picture :media="$service->cover"
                               sizes="(max-width: 900px) 100vw, 72vw"
                               :priority="true"
                               img-class="offering__image" />
                </div>

                @if ($service->cover->caption)
                    <figcaption><x-t :of="$service->cover" field="caption" /></figcaption>
                @endif
            </figure>
        @endif

        {{--
            The only unescaped output here. description_html is written by
            exactly one thing — App\Support\RichText, through the saving hook
            on the Service model — and is outside $fillable.
        --}}
        @if ($service->description_html)
            {{-- TASK 36b A1: sanitised HTML cannot be wrapped by an escaping component, so the
                 element it already has carries the declaration. See App\Support\Fallback. --}}
        <div class="offering__body"{!! \App\Support\Fallback::attributes($service, 'description_html') !!}>
                {!! $service->description_html !!}
            </div>
        @endif

        @if ($service->gallery->isNotEmpty())
            <section class="offering__gallery" aria-labelledby="gallery-title">
                <h2 class="offering__gallery-title" id="gallery-title">
                    {{ Settings::get('services.gallery_heading') }}
                </h2>

                <ul class="offering__grid" role="list">
                    @foreach ($service->gallery as $frame)
                        <li>
                            <x-picture :media="$frame"
                                       sizes="(max-width: 700px) 50vw, 24vw"
                                       img-class="offering__grid-image" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <a class="offering__cta" href="{{ route('contact.show') }}">
            {{ $service->cta_label ?: Settings::get('services.cta') }}
            <span class="offer__cta-rule" aria-hidden="true"></span>
        </a>
    </article>
</x-layouts::public>
