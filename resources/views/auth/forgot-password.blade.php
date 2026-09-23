<x-layouts::admin title="Reset your password">
    <section class="auth">
        <h1 class="auth__title">Reset your password</h1>

        <p class="auth__lede">
            Enter your email address and we will send a link to set a new password.
        </p>

        @if (session('status'))
            <p class="auth-status" role="status" aria-live="polite">{{ session('status') }}</p>
        @endif

        <x-auth-errors />

        <form method="POST" action="{{ route('password.email') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" required autofocus>
            </div>

            <button type="submit" class="auth-submit">Send the link</button>
        </form>

        <p class="auth__aside"><a href="{{ route('login') }}">Back to sign in</a></p>
    </section>
</x-layouts::admin>
