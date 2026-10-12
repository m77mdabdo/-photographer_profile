{{-- See forgot-password.blade.php: this was on the admin layout too. --}}
<x-layouts::auth :title="__('studio.auth.confirm_heading')">
    <div class="auth-card">
        <h1 class="auth-card__title">{{ __('studio.auth.confirm_heading') }}</h1>
        <span class="auth-card__rule"></span>

        <p class="auth-card__lede">{{ __('studio.auth.confirm_lede') }}</p>

        <x-auth-errors />

        <form method="POST" action="{{ route('password.confirm') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="password">{{ __('studio.auth.password') }}</label>
                <input id="password" name="password" type="password"
                       autocomplete="current-password" required autofocus dir="ltr">
            </div>

            <button type="submit" class="auth-submit">
                {{ __('studio.auth.confirm_submit') }}
                <span class="auth-submit__rule" aria-hidden="true"></span>
            </button>
        </form>
    </div>
</x-layouts::auth>
