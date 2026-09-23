<x-layouts::admin title="Confirm your password">
    <section class="auth">
        <h1 class="auth__title">Confirm your password</h1>

        <p class="auth__lede">
            This is a sensitive area. Please confirm your password to continue.
        </p>

        <x-auth-errors />

        <form method="POST" action="{{ route('password.confirm') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password"
                       autocomplete="current-password" required autofocus>
            </div>

            <button type="submit" class="auth-submit">Confirm</button>
        </form>
    </section>
</x-layouts::admin>
