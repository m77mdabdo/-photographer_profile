@props(['seo' => null])
<!DOCTYPE html>
{{--
    The resolved theme is written here, on the server, before the document is
    sent. That is what prevents a flash of the wrong palette: by the time the
    first paint happens the attribute is already in the DOM. With no stored
    preference the attribute is absent entirely and the stylesheet's
    prefers-color-scheme query governs.
--}}
<html lang="{{ str_replace('_', '-', config('seo.locale')) }}" dir="ltr"@if ($theme->attribute()) data-theme="{{ $theme->attribute() }}"@endif>
{{-- dir is hard-coded to ltr for now; it becomes config-driven when a RTL locale is added. --}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <x-seo :page="$seo" />

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
    @vite(['resources/css/public.css'])
</head>
<body>
    {{--
        Texture layers. Fixed, inert, above the background and below content.
        Marked aria-hidden because they carry no information whatsoever.
    --}}
    <div class="texture" aria-hidden="true"></div>
    <div class="vignette" aria-hidden="true"></div>

    {{-- First focusable element on the page, visible only once focused. --}}
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="site-header">
        <a class="site-header__mark" href="{{ route('home') }}">{{ config('seo.site_name') }}</a>

        <nav class="site-nav" aria-label="Primary">
            {{--
                Labels from the settings table, destinations still placeholder:
                the real routes land in a later task. The key is the address the
                template asks for; the word a visitor reads is the client's.
            --}}
            <ul role="list" class="site-nav__list">
                @foreach (['work', 'archive', 'about', 'journal', 'contact'] as $item)
                    @if ($label = \App\Support\Settings::get('nav.'.$item))
                        <li><a href="#">{{ $label }}</a></li>
                    @endif
                @endforeach
            </ul>
        </nav>
    </header>

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    <footer class="site-footer">
        <div class="site-footer__group">
            {{-- Placeholder contact details until the settings table exists. --}}
            <a href="mailto:hello@example.com">hello@example.com</a>
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
