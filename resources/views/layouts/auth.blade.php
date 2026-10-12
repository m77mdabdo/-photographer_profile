@props(['title' => 'Sign in'])
<!DOCTYPE html>
{{--
    THE FRONT DOOR GETS ITS OWN LAYOUT.

    The admin layout is a working surface: always dark, sidebar, dense. The
    sign-in page is not a working surface — it is the first thing anybody sees
    of this product, it is seen by exactly two people but it is seen every day,
    and the client named it as a priority.

    So it follows the PUBLIC site's conventions rather than the admin's: both
    registers, the real typefaces, the hard edges, the hairline accent rule. A
    studio whose website is composed and whose login is a grey box has told you
    which half it cared about.

    lang and dir are server-rendered from the active locale, exactly as the
    public layout does it — there is no second chance to set writing direction.
--}}
<html lang="{{ \App\Support\Locale::html() }}"
      dir="{{ \App\Support\Locale::direction() }}"
      @if ($theme?->attribute()) data-theme="{{ $theme->attribute() }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Never indexed, under any circumstances. --}}
    <x-seo :page="\App\Support\SeoData::make(title: $title, robots: 'noindex, nofollow, noarchive')" />

    @vite(['resources/css/admin.css'])
</head>
<body class="auth-shell">
    <a class="skip-link" href="#auth-main">{{ __('Skip to content') }}</a>

    <div class="auth-split">
        {{--
            THE IDENTITY PANEL.

            The studio's name in the display face at a size the public hero
            would recognise, over a hairline rule in the accent. No photograph:
            this page is served before authentication and must not leak a
            client's work to whoever typed the URL.
        --}}
        <aside class="auth-split__identity" aria-hidden="true">
            <p class="auth-brand">{{ \App\Support\Settings::get('site.name', config('app.name')) }}</p>
            <span class="auth-brand__rule"></span>
            <p class="auth-brand__note">{{ __('studio.login.intro') }}</p>
        </aside>

        <main class="auth-split__form" id="auth-main" tabindex="-1">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
