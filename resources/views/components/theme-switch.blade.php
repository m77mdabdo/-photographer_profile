@props(['current'])
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

    {{-- Comes back to exactly where the visitor was, query string and all. --}}
    <input type="hidden" name="return_to" value="{{ Request::getRequestUri() }}">

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
