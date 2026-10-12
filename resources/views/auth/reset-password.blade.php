{{-- See forgot-password.blade.php: this was on the admin layout too. --}}
<x-layouts::auth :title="__('studio.auth.choose_heading')">
    <div class="auth-card">
        <h1 class="auth-card__title">{{ __('studio.auth.choose_heading') }}</h1>
        <span class="auth-card__rule"></span>

        <x-auth-errors />

        <form method="POST" action="{{ route('password.update') }}" class="auth-form">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="field">
                <label for="email">{{ __('studio.auth.email') }}</label>
                <input id="email" name="email" type="email"
                       value="{{ old('email', $request->email) }}"
                       autocomplete="username" required autofocus
                       dir="ltr" spellcheck="false">
            </div>

            <div class="field">
                <label for="password">{{ __('studio.auth.choose_new') }}</label>
                <input id="password" name="password" type="password"
                       autocomplete="new-password" required dir="ltr">
            </div>

            <div class="field">
                <label for="password_confirmation">{{ __('studio.auth.choose_confirm') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" required dir="ltr">
            </div>

            <button type="submit" class="auth-submit">
                {{ __('studio.auth.choose_save') }}
                <span class="auth-submit__rule" aria-hidden="true"></span>
            </button>
        </form>
    </div>
</x-layouts::auth>
