{{--
    Validation errors are announced, not merely displayed: aria-live means a
    screen reader hears them when they appear, without moving focus.
--}}
<div aria-live="assertive" role="alert" class="auth-errors">
    @if ($errors->any())
        <p class="auth-errors__title">There {{ $errors->count() === 1 ? 'is a problem' : 'are problems' }} with what you entered:</p>

        <ul role="list" class="auth-errors__list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
</div>
