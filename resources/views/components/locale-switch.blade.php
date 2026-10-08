@php
    use App\Support\Locale;

    $current = Locale::current();
@endphp
{{--
    THE LOCALE SWITCHER.

    Built to the same pattern as the theme switch beside it: a real form, real
    submit buttons, one POST, and a redirect back to the same page in the other
    language. No JavaScript, because the public site's baseline is that nothing
    requires any — and because a language switcher is the last control that
    should depend on a script having loaded.

    WHY BUTTONS AND NOT LINKS

    A link would be simpler and would also work. It is not used because this
    writes a preference cookie, and the rule in SetLocale — no cookie without a
    deliberate choice — only holds if choosing is an action rather than a
    navigation. A crawler following a link must not acquire a language
    preference; a crawler cannot submit this form.

    Crawlers still find both locales through the hreflang alternates in <head>,
    which are plain <link> elements carrying the real URLs.

    EACH LANGUAGE IS NAMED IN ITSELF

    "العربية", never "Arabic". Somebody who reads only Arabic has to be able to
    find the button, and the one thing they certainly cannot read is the English
    word for their own language.
--}}
<form method="POST" action="{{ route('locale.store') }}" class="locale-switch">
    @csrf

    {{--
        The path, not the full request URI. LocaleController strips the query
        string anyway — a ?page=4 carried across a language change lands on a
        pagination state that may not exist — but sending the path makes that
        intent visible here rather than only in the controller.
    --}}
    <input type="hidden" name="return_to" value="{{ request()->getPathInfo() }}">

    <fieldset class="locale-switch__set">
        <legend class="sr-only">{{ __('Language') }}</legend>

        @foreach (Locale::codes() as $code)
            <button type="submit"
                    name="locale"
                    value="{{ $code }}"
                    class="locale-switch__option"
                    lang="{{ Locale::html($code) }}"
                    @if ($code === $current) aria-current="true" @endif
                    aria-pressed="{{ $code === $current ? 'true' : 'false' }}">
                {{ Locale::native($code) }}

                {{--
                    The English name is announced only to assistive tech, so a
                    screen reader in either language reads something meaningful
                    without the visible control carrying two labels.
                --}}
                <span class="sr-only">({{ Locale::name($code) }}@if ($code === $current), {{ __('current') }}@endif)</span>
            </button>
        @endforeach
    </fieldset>
</form>
