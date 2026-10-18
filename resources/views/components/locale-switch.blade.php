@props([
    /*
     * THE BEM BLOCK, which is the only thing that differs between the three
     * places this control appears. See the note below.
     */
    'block' => 'locale-switch',

    /* The visually-hidden utility the surrounding stylesheet defines. */
    'sr' => 'sr-only',

    /* What the fieldset announces. */
    'legend' => null,

    /* An optional visible word beside the languages, e.g. "Interface". */
    'label' => null,

    /*
     * TASK 36 C3 — AN EXPLICIT RETURN PATH, for the caller that knows better
     * than the request does. The error page passes the home page, because
     * coming back to an address that 404s is coming back to nothing.
     */
    'returnTo' => null,
])
@php
    use App\Support\Locale;
    use App\Support\ReturnPath;

    $current = Locale::current();

    /*
     * ONE DEFINITION OF "WHERE THE VISITOR WAS", shared with the theme
     * switcher. This used to be a keepQuery prop deciding between the path
     * and the raw request URI; ReturnPath makes the same public/private
     * distinction in one place, and bounds what a public page echoes back.
     * LocaleController makes the matching decision about where to redirect.
     */
    $returnTo ??= ReturnPath::current(request());
@endphp
{{--
    ───────────────────────────────────────────────────────────────────────────
    THE LOCALE SWITCHER. ONE CONTRACT, THREE REGISTERS.

    This renders in the public header, in the panel's topbar (Task 34b) and on
    the front door (Task 34c). The three look nothing alike and must not: a
    marketing header, an admin chrome and a sign-in page have different
    registers, and each stylesheet dresses its own block.

    What they MUST share is everything below the surface, which is why this is
    one file with a `block` prop rather than three files that drift:

      · one endpoint — route('locale.store'), writing the one cookie SetLocale
        reads. There is no second mechanism anywhere in this application.
      · a real form with a real submit button per language, so it works with
        scripting off, and so no browser can acquire a language preference by
        prefetching a link.
      · aria-pressed AND aria-current on the active option, because the state
        must be readable by a screen reader and not only visible.
      · lang and dir on each button, so "العربية" is announced in Arabic by an
        English screen reader rather than spelled out.
      · each language named TWICE: visibly in itself, because somebody who
        reads only Arabic cannot be expected to find the word "Arabic"; and in
        the reader's current language for assistive technology.

    Before Task 34c there were two copies of this markup. The second was
    written for the panel and the first never gained what the second learned.
    ───────────────────────────────────────────────────────────────────────────
--}}
<form method="POST"
      action="{{ route('locale.store') }}"
      class="{{ $block }}"
      {{-- The harness looks for this on every surface; see browser-check.mjs. --}}
      data-locale-switcher="{{ $current }}">
    @csrf

    {{--
        The path, and behind a login its query too. A dashboard deep link
        carries tableFilters and a password-reset address carries ?email=, and
        losing either sends somebody back to the start of something.
    --}}
    <input type="hidden" name="return_to" value="{{ $returnTo }}">

    <fieldset class="{{ $block }}__set">
        <legend class="{{ $sr }}">{{ $legend ?? __('studio.controls.language') }}</legend>

        @if (isset($icon))
            <span class="{{ $block }}__icon" aria-hidden="true">{{ $icon }}</span>
        @endif

        @if ($label !== null)
            <span class="{{ $block }}__label" aria-hidden="true">{{ $label }}</span>
        @endif

        @foreach (Locale::codes() as $code)
            <button type="submit"
                    name="locale"
                    value="{{ $code }}"
                    class="{{ $block }}__option"
                    lang="{{ Locale::html($code) }}"
                    dir="{{ Locale::direction($code) }}"
                    @if ($code === $current) aria-current="true" @endif
                    aria-pressed="{{ $code === $current ? 'true' : 'false' }}">
                {{ Locale::native($code) }}

                <span class="{{ $sr }}">
                    ({{ Locale::translatedName($code) }}@if ($label !== null), {{ $label }}@endif@if ($code === $current), {{ __('studio.controls.current') }}@endif)
                </span>
            </button>
        @endforeach
    </fieldset>
</form>
