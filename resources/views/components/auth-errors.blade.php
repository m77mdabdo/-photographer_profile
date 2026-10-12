{{--
    Validation errors are announced, not merely displayed: aria-live means a
    screen reader hears them when they appear, without moving focus.

    THE WHITESPACE MATTERS. The styling is guarded by `:not(:empty)`, and a
    Blade `@if` on its own line leaves newlines inside the div — so the element
    was never empty, and an accented bordered box sat above the form on every
    clean page load. It read as a broken input.

    The region stays in the DOM either way, because aria-live only announces
    changes to a region the screen reader was already observing.
--}}
<div aria-live="assertive" role="alert" class="auth-errors">@if ($errors->any())<p class="auth-errors__title">There {{ $errors->count() === 1 ? 'is a problem' : 'are problems' }} with what you entered:</p><ul role="list" class="auth-errors__list">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif</div>
