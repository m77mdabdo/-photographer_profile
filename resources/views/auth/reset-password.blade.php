<x-layouts::admin title="Choose a new password">
    <section class="auth">
        <h1 class="auth__title">Choose a new password</h1>

        <x-auth-errors />

        <form method="POST" action="{{ route('password.update') }}" class="auth-form">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email"
                       value="{{ old('email', $request->email) }}"
                       autocomplete="username" required autofocus>
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password"
                       autocomplete="new-password" required>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm the new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="auth-submit">Save the new password</button>
        </form>
    </section>
</x-layouts::admin>
