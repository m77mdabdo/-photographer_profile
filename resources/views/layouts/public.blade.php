@props(['seo' => null, 'structuredData' => null])
<!DOCTYPE html>
{{--
    The resolved theme is written here, on the server, before the document is
    sent. That is what prevents a flash of the wrong palette: by the time the
    first paint happens the attribute is already in the DOM. With no stored
    preference the attribute is absent entirely and the stylesheet's
    prefers-color-scheme query governs.
--}}
{{--
    lang AND dir ARE SERVER-RENDERED, for the same reason the theme is.

    Writing direction is not a decoration that can settle in after the first
    paint. A page that renders LTR and then flips to RTL does not flash a
    colour — it relays out the entire document, moves every scrollbar, rail and
    alignment, and does it after the reader has already started reading. There
    is no JavaScript involved in either attribute and there is nothing to
    reconcile: by the time the first byte of <body> is sent, both are final.
--}}
<html lang="{{ \App\Support\Locale::html() }}" dir="{{ \App\Support\Locale::direction() }}"@if ($theme->attribute()) data-theme="{{ $theme->attribute() }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        FIRST, BEFORE ANYTHING ELSE.

        Decides whether motion is allowed and writes one class name, before the
        stylesheet and before the first paint. See the component for why this
        cannot live in the module.
    --}}
    <x-motion-boot />

    <x-seo :page="$seo" />

    {{--
        hreflang. Every locale, plus x-default, on every public page.

        Emitted beside the canonical rather than in the SEO component because
        these describe the page's SIBLINGS, not the page — and keeping them
        here makes it visible that the canonical is per-locale while the
        alternates are the set.
    --}}
    <x-hreflang />

    {{--
        THE SITE'S OWN IDENTITY, on every page.

        The studio and the website are facts about this installation rather
        than about any one page, so they are emitted here instead of being
        repeated by every controller. Both are built from the settings table
        and both return nothing at all when the studio has no name stored, so
        a fresh install emits no empty graph.

        Page-specific blocks — BlogPosting, CreativeWork, Service, a
        breadcrumb trail — come through $structuredData from the controller
        that actually holds the record.
    --}}
    <x-structured-data :data="[
        \App\Support\StructuredData::studio(),
        \App\Support\StructuredData::website(),
    ]" />

    @isset($structuredData)
        <x-structured-data :data="$structuredData" />
    @endisset

    {{--
        Only the display face is preloaded, and only its Latin subset.

        It sets the wordmark, which is the largest thing on the page, the first
        thing a visitor sees and the one swap that would be plainly visible.
        The mono is above the fold too — the stage rail, the navigation and the
        exposure line are all set in it — but at eleven to thirteen pixels,
        against a metric-matched Menlo or Consolas fallback, its swap costs a
        glyph substitution nobody registers and no reflow at all. It is not
        worth taking bandwidth from the hero photograph, which is the LCP
        element. The sans waits for the same reason.
    --}}
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>

    {{--
        CSS only. The public site runs no JavaScript: nothing here needs it,
        and loading the bundle shipped ~51 kB of axios that no public page ever
        called. The JS entry still builds; it is added back when there is
        something to run.
    --}}
    {{--
        THE CSS BLOCKS; THE JAVASCRIPT DOES NOT.

        @vite emits the module with type="module", which is deferred by
        definition — it cannot delay parsing, layout or the first paint, and
        the hero photograph is painted and eligible for LCP before a byte of
        GSAP is executed.
    --}}
    @vite(['resources/css/public.css', 'resources/js/public.js'])
</head>
<body>
    {{--
        Texture layers. Fixed, inert, above the background and below content.
        Marked aria-hidden because they carry no information whatsoever.
    --}}
    <div class="texture" aria-hidden="true"></div>
    <div class="vignette" aria-hidden="true"></div>

    {{-- First focusable element on the page, visible only once focused. --}}
    <a class="skip-link" href="#main">{{ __('studio.a11y.skip') }}</a>

    <header class="site-header">
        <a class="site-header__mark" href="{{ route('home') }}">{{ config('seo.site_name') }}</a>

        <nav class="site-nav" aria-label="Primary">
            {{--
                Labels from the settings table, destinations still placeholder:
                the real routes land in a later task. The key is the address the
                template asks for; the word a visitor reads is the client's.
            --}}
            @php
                /*
                 * Every item now has a destination. The routeless branch below
                 * is kept because the next section to be built will need it
                 * again, and because a nav item without a route must stay
                 * unfocusable and unannounced rather than become a link to
                 * nowhere — the journal spent six tasks as one of these.
                 */
                $navRoutes = [
                    'work' => route('work.index'),
                    'services' => route('services.index'),
                    'archive' => route('archive.index'),
                    'journal' => route('journal.index'),
                    'about' => route('about'),
                    'contact' => route('contact.show'),
                ];
            @endphp
            <ul role="list" class="site-nav__list">
                @foreach ($navRoutes as $item => $href)
                    @if ($label = \App\Support\Settings::get('nav.'.$item))
                        <li>
                            @if ($href)
                                <a href="{{ $href }}"
                                   @if (request()->url() === $href || str_starts_with(request()->url(), $href.'/')) aria-current="page" @endif>{{ $label }}</a>
                            @else
                                <span aria-disabled="true">{{ $label }}</span>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
        </nav>

        {{--
            The language switcher sits in the header, not the footer, because
            it is the one control a visitor in the wrong language needs before
            they read anything else. The theme switch stays in the footer: a
            palette is a comfort, a language is comprehension.
        --}}
        <x-locale-switch />
    </header>

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    <footer class="site-footer">
        <div class="site-footer__group">
            {{-- The footer route to the enquiry form, alongside the nav's. --}}
            @if ($contactLabel = \App\Support\Settings::get('nav.contact'))
                <a href="{{ route('contact.show') }}">{{ $contactLabel }}</a>
            @endif

            {{--
                The legal pages, linked ONLY when they are actually published.

                A footer link to an unpublished privacy notice is a 404 in the
                one place a regulator, a client's legal team or a browser's
                autofill will look for it. The Page model refuses to delete a
                locked page for the same reason; this is the other half of that
                arrangement.
            --}}
            @foreach (\App\Support\PageLinks::legal() as $legal)
                <a href="{{ $legal['url'] }}">{{ $legal['label'] }}</a>
            @endforeach
        </div>

        <div class="site-footer__group">
            <ul role="list" class="site-footer__social">
                <li><a href="#">Instagram</a></li>
                <li><a href="#">Bluesky</a></li>
            </ul>
        </div>

        <div class="site-footer__group">
            <x-theme-switch :current="$theme" />
        </div>

        <p class="site-footer__copyright">
            &copy; {{ now()->year }} {{ config('seo.site_name') }}
        </p>
    </footer>
</body>
</html>
