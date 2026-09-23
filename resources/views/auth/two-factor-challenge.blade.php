<x-layouts::admin title="Two-factor authentication">
    <section class="auth">
        <h1 class="auth__title">Confirm it is you</h1>

        <p class="auth__lede">
            Enter the code from your authenticator app, or one of your recovery codes.
        </p>

        <x-auth-errors />

        <form method="POST" action="{{ route('two-factor.login') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="code">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric"
                       autocomplete="one-time-code" autofocus>
            </div>

            <div class="field">
                <label for="recovery_code">Recovery code</label>
                <input id="recovery_code" name="recovery_code" type="text"
                       autocomplete="one-time-code">
                <p class="field__hint">Use this only if you cannot reach your authenticator app.</p>
            </div>

            <button type="submit" class="auth-submit">Continue</button>
        </form>
    </section>
</x-layouts::admin>
