<x-layouts::auth :title="__('studio.login.heading')">
    <div class="auth-card">
        {{--
            The heading is in the display face at h2, over the same hairline
            the public site rules its sections with. Small thing; it is also
            the difference between "a form" and "a page".
        --}}
        <h1 class="auth-card__title">{{ __('studio.login.heading') }}</h1>
        <span class="auth-card__rule"></span>

        @if (session('status'))
            <p class="auth-status" role="status" aria-live="polite">{{ session('status') }}</p>
        @endif

        <x-auth-errors />

        <form method="POST" action="{{ route('login') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="email">{{ __('Email address') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" required autofocus
                       dir="ltr" spellcheck="false">
            </div>

            <div class="field">
                <label for="password">{{ __('Password') }}</label>
                <input id="password" name="password" type="password"
                       autocomplete="current-password" required dir="ltr">
            </div>

            <div class="field field--inline">
                <input id="remember" name="remember" type="checkbox">
                <label for="remember">{{ __('Stay signed in on this device') }}</label>
            </div>

            <button type="submit" class="auth-submit">
                {{ __('studio.login.heading') }}
                {{-- The accent rule, drawn under the action the way the public
                     site draws one under every primary link. --}}
                <span class="auth-submit__rule" aria-hidden="true"></span>
            </button>
        </form>

        <p class="auth-card__aside">
            <a href="{{ route('password.request') }}">{{ __('Forgotten your password?') }}</a>
        </p>

        {{--
            Said before signing in, not after. Somebody reaching for a password
            manager should already know a second factor is coming, rather than
            discovering it on the next screen.
        --}}
        <p class="auth-card__note">{{ __('studio.login.note') }}</p>
    </div>
</x-layouts::auth>
