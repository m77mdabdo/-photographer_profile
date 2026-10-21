@props(['seo' => null, 'structuredData' => null])
@php
    /*
     * TASK 36 C3 — WHERE THE PREFERENCE FORMS COME BACK TO.
     *
     * Normally the page itself. On an error page there is no page: the address
     * is whatever was asked for, so sending somebody back to it after they
     * change the language returns them to the same 404 — and putting it in the
     * body reflects a caller-chosen string into a gzipped response that also
     * holds the CSRF token. Both switchers get the home page instead.
     *
     * Derived from the same flag that suppresses the canonical and the
     * hreflang set, so the three cannot disagree.
     */
    $preferenceReturnTo = $seo?->addressless ? '/' : null;
@endphp
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

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
    @unless ($seo?->addressless)
        <x-hreflang />
    @endunless

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
    {{--
        34e — AND THE PRELOAD FOLLOWS THE LOCALE.

        An English visitor must never fetch an Arabic face and an Arabic
        visitor must never fetch a Latin display face they will not see a
        glyph of. unicode-range already guarantees the browser does not USE
        the wrong one; a preload is the one thing that would fetch it anyway,
        because a preload names a file rather than a range.

        One face each, and in both cases it is the one the wordmark is set in
        — the largest thing above the fold and the only swap a visitor would
        actually notice. The text face waits for the same reason the Latin
        sans does: at body size, against a system Arabic fallback, its swap
        costs a glyph substitution rather than a relayout of the hero.
    --}}
    @if (\App\Support\Locale::isDefault())
        <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    @else
        <link rel="preload" href="/fonts/rubik-arabic.woff2" as="font" type="font/woff2" crossorigin>
    @endif

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
    {{--
        THE FAVICON, WHICH THIS SITE DID NOT HAVE.

        public/favicon.ico is Laravel's 0-byte placeholder and no icon link
        existed in any layout, so every browser tab showed a generic page
        icon. Chosen from the media library — see App\Support\SiteImages —
        and omitted entirely when unset rather than pointing at the empty
        file, because a 0-byte icon is a broken image where no icon is just
        the browser's default.
    --}}
    @if ($favicon = \App\Support\SiteImages::faviconLink())
        <link rel="icon" type="{{ $favicon['type'] }}" href="{{ $favicon['url'] }}">
        <link rel="apple-touch-icon" href="{{ $favicon['url'] }}">
    @endif

    @vite(['resources/css/public.css', 'resources/js/public.js'])

    {{-- Per-page assets. Today only the page transition (Task 38 Part F),
         linked by the pages that opt into it. --}}
    @stack('head')
</head>
<body>
    {{--
        Texture layers. Fixed, inert, above the background and below content.
        Marked aria-hidden because they carry no information whatsoever.
    --}}
    <div class="texture" aria-hidden="true"></div>
    <div class="vignette" aria-hidden="true"></div>

    {{--
        THE PERSISTENT CONTACT LINK — Task 38 G1, phones and tablets only.

        The reference keeps a pill in the trailing bottom corner of every
        mobile screen. Ours is the studio's own invitation, closing.cta — the
        same words the home page ends on, so nothing new is said — pointing at
        the contact page, and absent ON the contact pages, where it would
        point at the page the visitor is already on.

        It is placed early in the source but after the skip link's target is
        defined, so it is one ordinary link in the tab order: a link, not a
        button, because it goes somewhere. components/layout.css reserves its
        height at the foot of every page so it can never sit on the enquiry
        form's submit button or the pager, and keeps it clear of the iOS home
        indicator.
    --}}
    {{-- Both languages: the Arabic routes are named ar.contact.*, and the
         first version matched only contact.* and so showed the pill on the
         Arabic contact page, pointing at itself. --}}
    @unless (request()->routeIs('contact.*', 'ar.contact.*'))
        <a class="contact-pill" href="{{ route('contact.show') }}">{{ \App\Support\SiteCopy::get('closing.cta') }}</a>
    @endunless

    {{-- First focusable element on the page, visible only once focused. --}}
    <a class="skip-link" href="#main">{{ __('studio.a11y.skip') }}</a>

    <header class="site-header">
        <a class="site-header__mark" href="{{ route('home') }}">{{ config('seo.site_name') }}</a>

        <nav class="site-nav" aria-label="{{ \App\Support\SiteCopy::get('footer.nav_label') }}">
            {{--
                ═══════════════════════════════════════════════════════════════
                THE NAVIGATION IS DATA (Task 34 D1).
                
                This was six entries hardcoded here, with only their LABELS
                coming from settings — so the studio could rename "Work" and
                could not reorder it, remove it, or add the page they had just
                written. Adding one meant a developer and a deploy.
                
                App\Support\Navigation resolves each item to an address and
                drops any that no longer has one, so this template never has
                to ask whether a link is real. Cached per locale and dropped
                by the models' own save hooks.
                ═══════════════════════════════════════════════════════════════
            --}}
            @php($navItems = \App\Support\Navigation::for(\App\Models\Menu::HEADER))

            @if ($navItems !== [])
                <ul role="list" class="site-nav__list">
                    @foreach ($navItems as $item)
                        <li>
                            <a href="{{ $item['url'] }}"
                               @if ($item['external']) target="_blank" rel="noopener" @endif
                               @if (! $item['external'] && (request()->url() === $item['url'] || str_starts_with(request()->url(), $item['url'].'/'))) aria-current="page" @endif>{{ $item['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>

        {{--
            The language switcher sits in the header, not the footer, because
            it is the one control a visitor in the wrong language needs before
            they read anything else. The theme switch stays in the footer: a
            palette is a comfort, a language is comprehension.
        --}}
        <x-locale-switch :return-to="$preferenceReturnTo" />
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

        {{--
            THE SOCIAL LINKS, OWNED BY THE OWNER.

            These were two <a href="#"> elements naming Instagram and Bluesky —
            which decided, in a Blade template, which two networks this studio
            uses for ever, and pointed both of them at nothing.

            Three slots, each a label and a URL. A slot renders only when it has
            BOTH: a label with no address is a link that goes nowhere, and an
            address with no label is a link nobody can read. A studio that uses
            none gets no list rather than an empty one — hence the @if around
            the <ul> as well as around each item.
        --}}
        @php($social = \App\Support\SocialLinks::all())

        @if ($social !== [])
            <div class="site-footer__group">
                <ul role="list" class="site-footer__social">
                    @foreach ($social as $link)
                        <li>
                            <a href="{{ $link['url'] }}" rel="me noopener">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="site-footer__group">
            <x-theme-switch :current="$theme" :return-to="$preferenceReturnTo" />
        </div>

        <p class="site-footer__copyright">
            {{--
                The symbol and the year are structural; the sentence is not.

                The year needs no localising helper: this site uses WESTERN
                numerals in both locales — see docs/decisions/0005-numerals.md —
                so now()->year is already correct in Arabic. A
                Numerals::year() wrapper would return its argument unchanged,
                which is a pattern with one implementation and therefore not an
                abstraction.
            --}}
            &copy; {{ now()->year }}
            {{ \App\Support\Settings::get('site.name', config('seo.site_name')) }}
            {{ \App\Support\SiteCopy::get('footer.copyright') }}
        </p>
    </footer>
</body>
</html>
