{{--
    THE AUTH LAYOUT, NOT THE ADMIN ONE.

    This page shipped on <x-layouts::admin>, which meant an UNAUTHENTICATED
    visitor asking for a password link was served the whole admin shell: the
    sidebar, every navigation label, and — because that chrome had not been
    touched since before the panel's navigation was rebuilt — a stale list
    naming screens that no longer exist. Anybody who typed /forgot-password
    could read the studio's entire back-office structure.

    layouts.auth is the front door's layout: no navigation, noindex, both
    registers, and the studio's own typefaces. See docs/decisions/0004.
--}}
<x-layouts::auth :title="__('studio.auth.reset_heading')">
    <div class="auth-card">
        <h1 class="auth-card__title">{{ __('studio.auth.reset_heading') }}</h1>
        <span class="auth-card__rule"></span>

        <p class="auth-card__lede">{{ __('studio.auth.reset_lede') }}</p>

        @if (session('status'))
            <p class="auth-status" role="status" aria-live="polite">{{ session('status') }}</p>
        @endif

        <x-auth-errors />

        <form method="POST" action="{{ route('password.email') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="email">{{ __('studio.auth.email') }}</label>
                {{-- dir="ltr" on the input itself: an email address reads
                     left-to-right in every language. --}}
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" required autofocus
                       dir="ltr" spellcheck="false">
            </div>

            <button type="submit" class="auth-submit">
                {{ __('studio.auth.reset_send') }}
                <span class="auth-submit__rule" aria-hidden="true"></span>
            </button>
        </form>

        <p class="auth-card__aside">
            <a href="{{ route('login') }}">{{ __('studio.auth.back') }}</a>
        </p>
    </div>
</x-layouts::auth>
