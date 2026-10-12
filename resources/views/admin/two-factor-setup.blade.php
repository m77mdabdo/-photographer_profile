<x-layouts::admin title="Two-factor authentication">
    <h1 class="admin-heading">Two-factor authentication required</h1>

    {{--
        NO FLASH BLOCK HERE, DELIBERATELY.

        The layout renders the status flash for every page, so a second copy on
        this page printed the same sentence twice, directly under a heading
        that said the same thing a third way — three statements of one fact,
        one of them word-for-word repeated.

        The layout is the single owner of that flash. A page that wants to say
        something extra says something else. A test asserts this file does not
        reach for it again.
    --}}
    <p class="admin-note">
        Enrol an authenticator app, then confirm a code to finish. You will be
        asked for a code from it each time you sign in.
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
