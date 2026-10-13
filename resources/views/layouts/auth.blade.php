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

    @vite(['resources/css/auth.css'])
</head>
<body class="auth-shell">
    <a class="skip-link" href="#auth-main">{{ __('studio.a11y.skip') }}</a>

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
            {{--
                ═══════════════════════════════════════════════════════════════
                THE STATUS FLASH, WHICH NOTHING HAS RENDERED SINCE TASK 33.
                
                Fortify answers in session('status'): "Two-factor
                authentication confirmed", "We have emailed your password
                reset link", "Your password has been reset". The custom
                admin's layout printed it, and two-factor-setup.blade.php
                carries a comment saying it deliberately does NOT print its
                own copy because "the layout is the single owner of that
                flash".
                
                Task 33 moved that page from layouts.admin to layouts.auth —
                and this layout never had the block. So enrolling a second
                factor, resetting a password and requesting a reset link have
                all been answering in silence, on the five pages where the
                reply is the only confirmation there is.
                
                Found in Task 34 Part B by the test that asserts the single
                owner: it had been reading the deleted admin's layout.
                ═══════════════════════════════════════════════════════════════
            --}}
            @if (session('status') || session('error'))
                <div class="auth-status" role="status" aria-live="polite">
                    @if (session('status'))
                        <p>{{ session('status') }}</p>
                    @endif

                    @if (session('error'))
                        <p class="auth-status--error">{{ session('error') }}</p>
                    @endif
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</body>
</html>
