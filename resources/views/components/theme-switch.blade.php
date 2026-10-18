@props([
    'current',

    /*
     * TASK 36 C3 — where to come back to. Defaults to the cleaned current
     * address; an error page passes the home page instead, because coming
     * back to an address that does not exist is coming back to nothing — and
     * because that address is whatever the caller typed. See App\Support\ReturnPath.
     */
    'returnTo' => null,
])
{{--
    Three real submit buttons in one form. Not a checkbox and not a link:
    changing a stored preference is a state change, so it is a POST, and a
    button is the only control that both reads correctly to assistive
    technology and works with no scripting at all.

    The current choice is marked with aria-pressed rather than being disabled,
    because a disabled control is unreachable by keyboard and gives no way to
    discover what is currently set.
--}}
<form method="POST" action="{{ route('theme.store') }}" class="theme-switch">
    @csrf

    {{--
        Comes back to where the visitor was: the path, plus the query
        parameters this site actually reads, with their values bounded.

        This was Request::getRequestUri() — the raw query string, whatever it
        held, reflected into the body of every public page. Those pages also
        carry the CSRF token and are gzipped, and a secret compressed together
        with attacker-chosen text is a BREACH oracle. App\Support\ReturnPath
        has the measurements.
    --}}
    <input type="hidden" name="return_to" value="{{ $returnTo ?? \App\Support\ReturnPath::current(request()) }}">

    <fieldset class="theme-switch__set">
        <legend class="sr-only">{{ \App\Support\SiteCopy::get('shared.theme_label') }}</legend>

        @foreach (\App\Support\Theme::cases() as $option)
            <button type="submit"
                    name="theme"
                    value="{{ $option->value }}"
                    class="theme-switch__option"
                    aria-pressed="{{ $current === $option ? 'true' : 'false' }}">
                {{ $option->label() }}
                @if ($current === $option)
                    <span class="sr-only">({{ __('studio.controls.current') }})</span>
                @endif
            </button>
        @endforeach
    </fieldset>
</form>
