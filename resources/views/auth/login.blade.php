<x-layouts::admin title="Sign in">
    <section class="auth">
        <h1 class="auth__title">Sign in</h1>

        @if (session('status'))
            <p class="auth-status" role="status" aria-live="polite">{{ session('status') }}</p>
        @endif

        <x-auth-errors />

        <form method="POST" action="{{ route('login') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" required autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password"
                       autocomplete="current-password" required>
            </div>

            <div class="field field--inline">
                <input id="remember" name="remember" type="checkbox">
                <label for="remember">Stay signed in on this device</label>
            </div>

            <button type="submit" class="auth-submit">Sign in</button>
        </form>

        <p class="auth__aside">
            <a href="{{ route('password.request') }}">Forgotten your password?</a>
        </p>
    </section>
</x-layouts::admin>
