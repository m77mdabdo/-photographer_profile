<x-layouts::admin title="Two-factor authentication">
    <h1 class="admin-heading">Two-factor authentication required</h1>

    @if (session('status'))
        <p class="auth-status" role="status" aria-live="polite">{{ session('status') }}</p>
    @endif

    <p class="admin-note">
        The admin area requires a confirmed second factor. Enrol an authenticator
        app, then confirm a code to finish.
    </p>

    {{-- Fortify supplies these endpoints; the markup is ours. --}}
    @if (! auth()->user()->two_factor_secret)
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}">
            @csrf
            <button type="submit" class="auth-submit">Begin setup</button>
        </form>
    @else
        <div class="two-factor-qr">
            {!! auth()->user()->twoFactorQrCodeSvg() !!}
        </div>

        <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="auth-form">
            @csrf

            <div class="field">
                <label for="code">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric"
                       autocomplete="one-time-code" required autofocus>
            </div>

            <x-auth-errors />

            <button type="submit" class="auth-submit">Confirm</button>
        </form>
    @endif
</x-layouts::admin>
