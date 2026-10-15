@php
    use App\Support\Locale;

    $current = Locale::current();
@endphp
{{--
    ───────────────────────────────────────────────────────────────────────────
    THE PANEL'S LANGUAGE, IN THE PANEL.

    Task 34b. The panel has been fully translated since Part F — 516 keys, RTL
    correct, every screen scanned — and there was no way to change its language
    from inside it. The locale came from a cookie that only the PUBLIC site's
    switcher wrote, so an Arabic-reading owner had to visit the public site,
    find the footer, switch, and come back. Nobody discovers that.

    THE SAME MECHANISM, NOT A SECOND ONE. This posts to the same endpoint the
    public switcher posts to, which writes the same cookie SetLocale has always
    read. Nothing new decides anything.

    A FORM, NOT LINKS, for the reason LocaleController gives: writing a
    preference is an action. In the panel there is a second reason — a link
    that changes state is a link a browser may prefetch, and the panel is full
    of them.

    THIS IS THE INTERFACE LANGUAGE AND SAYS SO. The per-field tabs on a
    translatable form are labelled with the same two words, and they mean
    something else entirely: the language of the CONTENT being written. Three
    things keep them apart, and none of them is colour alone:

      · this control carries a GLOBE; the content tabs carry a DOCUMENT.
      · this control says "Interface" beside the globe wherever there is room
        for it, and always to a screen reader.
      · this control is in the chrome, in the topbar, outside every card.

    See Translatable::tabs() for the other half.
    ───────────────────────────────────────────────────────────────────────────
--}}
<form method="POST"
      action="{{ route('locale.store') }}"
      class="studio-locale"
      data-studio-locale="{{ $current }}">
    @csrf

    {{--
        THE PATH AND ITS QUERY, unlike the public switcher, which sends the
        path alone. In the panel the query IS the page: a dashboard deep link
        carries tableFilters, and switching language on a filtered list must
        not quietly unfilter it. LocaleController keeps the query for private
        surfaces and drops it everywhere else.
    --}}
    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

    <fieldset class="studio-locale__set">
        <legend class="fi-sr-only">{{ __('studio.locale.interface_legend') }}</legend>

        <span class="studio-locale__icon" aria-hidden="true">
            <x-filament::icon icon="heroicon-m-globe-alt" />
        </span>

        {{-- Visible from 1024 up, where the topbar has room for a word. --}}
        <span class="studio-locale__label" aria-hidden="true">{{ __('studio.locale.interface') }}</span>

        @foreach (Locale::codes() as $code)
            <button type="submit"
                    name="locale"
                    value="{{ $code }}"
                    class="studio-locale__option"
                    lang="{{ Locale::html($code) }}"
                    dir="{{ Locale::direction($code) }}"
                    @if ($code === $current) aria-current="true" @endif
                    aria-pressed="{{ $code === $current ? 'true' : 'false' }}">
                {{ Locale::native($code) }}

                {{--
                    NAMED TWICE, DELIBERATELY. The visible label is the
                    language in ITSELF, because somebody who reads only Arabic
                    must be able to find the Arabic button. The announced one
                    adds the name in the language the panel is currently in,
                    and says what the control changes — so a screen reader
                    reaching this on an English panel hears "العربية (Arabic,
                    interface language)" rather than one word in a script the
                    voice may not even be set to read.
                --}}
                <span class="fi-sr-only">
                    ({{ Locale::translatedName($code) }}, {{ __('studio.locale.interface') }}@if ($code === $current), {{ __('studio.controls.current') }}@endif)
                </span>
            </button>
        @endforeach
    </fieldset>
</form>
