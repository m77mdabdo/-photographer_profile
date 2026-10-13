{{--
    ENROLMENT, ON THE FRONT DOOR'S LAYOUT.

    This page moved out of the custom admin in Task 33. It used to live at
    /admin/two-factor inside that admin's route group and render with its
    layout — which meant moving the group to /legacy would have carried the
    enrolment page with it, and deleting the group later would have removed
    the only way to enrol a second factor.

    It is Fortify's page in everything but markup, so it belongs with the
    other four Fortify pages on layouts.auth: no navigation, noindex, both
    registers, and — since Task 32 Part 0 — both languages. Its six strings
    were the last hardcoded English on a surviving screen.
--}}
<x-layouts::auth :title="__('studio.auth.setup_heading')">
    <div class="auth-card">
        <h1 class="auth-card__title">{{ __('studio.auth.setup_heading') }}</h1>
        <span class="auth-card__rule"></span>

        {{--
            NO FLASH BLOCK HERE, DELIBERATELY.

            The layout renders the status flash for every page, so a second
            copy on this page printed the same sentence twice, directly under a
            heading that said the same thing a third way. The layout is the
            single owner of that flash; a test asserts this file does not reach
            for it again.
        --}}
        <p class="auth-card__lede">{{ __('studio.auth.setup_lede') }}</p>

        {{-- Fortify supplies these endpoints; the markup is ours. --}}
        @if (! auth()->user()->two_factor_secret)
            <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="auth-form">
                @csrf

                <button type="submit" class="auth-submit">
                    {{ __('studio.auth.setup_begin') }}
                    <span class="auth-submit__rule" aria-hidden="true"></span>
                </button>
            </form>
        @else
            <div class="two-factor-qr">
                {!! auth()->user()->twoFactorQrCodeSvg() !!}
            </div>

            <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="auth-form">
                @csrf

                <div class="field">
                    <label for="code">{{ __('studio.auth.challenge_code') }}</label>
                    {{-- Digits, so LTR whatever the page's direction. --}}
                    <input id="code" name="code" type="text" inputmode="numeric"
                           autocomplete="one-time-code" required autofocus
                           dir="ltr" spellcheck="false">
                </div>

                <x-auth-errors />

                <button type="submit" class="auth-submit">
                    {{ __('studio.auth.setup_confirm') }}
                    <span class="auth-submit__rule" aria-hidden="true"></span>
                </button>
            </form>
        @endif
    </div>
</x-layouts::auth>
