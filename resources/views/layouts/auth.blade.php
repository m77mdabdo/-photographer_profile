@props(['title' => null])
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
    <x-seo :page="\App\Support\SeoData::make(title: $title ?? __('studio.login.heading'), robots: 'noindex, nofollow, noarchive')" />

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
                TASK 34c — THE LANGUAGE, BEFORE THE FORM.

                An owner who reads only Arabic met this page in English with no
                way out of it: the cookie SetLocale reads was written by the
                public site's header and, since 34b, by the panel's topbar —
                both of which are on the far side of a door they cannot read.

                IN THE LAYOUT, NOT IN A VIEW, so it is on all six Fortify
                pages at once: sign-in, the reset request, the reset form, the
                second-factor challenge, password confirmation and enrolment.
                Being locked out mid-flow is exactly when somebody needs it,
                and adding it view by view is how a page gets missed.

                FIRST IN THE FORM PANE, and therefore early in the tab order,
                because the person who needs this control cannot read anything
                below it. It costs a reader who does not need it two tabs; the
                skip link above jumps the lot.

                NOT IN THE IDENTITY PANEL. That aside is aria-hidden, and
                focusable content inside aria-hidden is unreachable to a
                screen-reader user while remaining reachable by Tab — the
                worst of both.
                ═══════════════════════════════════════════════════════════════
            --}}
            {{--
                No return-path prop: the front door is a private path, and
                App\Support\ReturnPath keeps the whole URI for those — which
                is what a password-reset link's ?email= needs.
            --}}
            <x-locale-switch
                block="auth-locale"
                :legend="__('studio.locale.interface_legend')"
                :label="__('studio.locale.interface')"
            >
                <x-slot:icon>
                    <x-heroicon-m-globe-alt />
                </x-slot:icon>
            </x-locale-switch>

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
