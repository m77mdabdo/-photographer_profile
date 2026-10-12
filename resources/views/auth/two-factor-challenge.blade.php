{{--
    See forgot-password.blade.php: this was on the admin layout too, and this
    one is reached HALF-SIGNED-IN — Fortify has the credentials but not the
    second factor — so serving the admin shell here announced the back office
    to somebody who had not finished proving who they were.
--}}
<x-layouts::auth :title="__('studio.auth.challenge_heading')">
    <div class="auth-card">
        <h1 class="auth-card__title">{{ __('studio.auth.challenge_heading') }}</h1>
        <span class="auth-card__rule"></span>

        <p class="auth-card__lede">{{ __('studio.auth.challenge_lede') }}</p>

        <x-auth-errors />

        <form method="POST" action="{{ route('two-factor.login') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="code">{{ __('studio.auth.challenge_code') }}</label>
                {{-- A six-digit code is digits, so the input is LTR and numeric
                     whatever the page's direction. --}}
                <input id="code" name="code" type="text" inputmode="numeric"
                       autocomplete="one-time-code" autofocus
                       dir="ltr" spellcheck="false">
            </div>

            <div class="field">
                <label for="recovery_code">{{ __('studio.auth.challenge_recovery') }}</label>
                <input id="recovery_code" name="recovery_code" type="text"
                       autocomplete="one-time-code" dir="ltr" spellcheck="false">
                <p class="field__hint">{{ __('studio.auth.challenge_recovery_hint') }}</p>
            </div>

            <button type="submit" class="auth-submit">
                {{ __('studio.auth.challenge_submit') }}
                <span class="auth-submit__rule" aria-hidden="true"></span>
            </button>
        </form>
    </div>
</x-layouts::auth>
