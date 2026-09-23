@props(['seo' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', config('seo.locale')) }}" dir="ltr">
{{-- dir is hard-coded to ltr for now; it becomes config-driven when a RTL locale is added. --}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <x-seo :page="$seo" />

    {{--
        CSS only. The public site runs no JavaScript: nothing here needs it,
        and loading the bundle shipped ~51 kB of axios that no public page ever
        called. The JS entry still builds; it is added back when there is
        something to run.
    --}}
    @vite(['resources/css/public.css'])
</head>
<body>
    {{-- First focusable element on the page, visible only once focused. --}}
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="site-header">
        <a class="site-header__mark" href="{{ route('home') }}">{{ config('seo.site_name') }}</a>

        <nav class="site-nav" aria-label="Primary">
            {{-- Placeholder destinations: real routes land in a later task. --}}
            <ul role="list" class="site-nav__list">
                <li><a href="#">Work</a></li>
                <li><a href="#">Archive</a></li>
                <li><a href="#">About</a></li>
                <li><a href="#">Journal</a></li>
                <li><a href="#">Contact</a></li>
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

        <p class="site-footer__copyright">
            &copy; {{ now()->year }} {{ config('seo.site_name') }}
        </p>
    </footer>
</body>
</html>
