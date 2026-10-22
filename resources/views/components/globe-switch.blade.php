@props(['returnTo' => null])
@php
    use App\Support\Locale;

    $current = Locale::current();
@endphp
{{--
    THE LANGUAGE CONTROL IN THE HEADER — a globe that opens the switcher.

    A native <details> disclosure around the same <x-locale-switch> form the
    rest of the site uses, so nothing about switching changes: one POST
    endpoint, one cookie, the same page in the other language, and it works
    with scripting off. <summary> is a real control — focusable, operated by
    Enter, Space and touch, and announced as expanded or collapsed by the
    browser itself. resources/js/public/disclosure.js adds what HTML cannot:
    Escape and a tap outside close it, and focus returns to the globe.

    Not role="menu": a menu promises arrow-key navigation and type-ahead, and
    two submit buttons in a form are buttons. A disclosure is the honest
    pattern for what this is.

    The panel is absolutely positioned against the inline end, so opening it
    moves nothing (CLS 0) and it opens toward the page in either direction.
--}}
<details class="globe" data-disclosure>
    <summary class="globe__toggle"
             aria-label="{{ __('studio.controls.language_menu', ['language' => Locale::native($current)]) }}">
        {{-- Sized and stroked in header.css: no width attribute here, so image
             counts that look for width="" stay counts of images. --}}
        <svg class="globe__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9"/>
            <ellipse cx="12" cy="12" rx="3.9" ry="9"/>
            <path d="M3.6 9h16.8M3.6 15h16.8"/>
        </svg>
        <span class="globe__code" aria-hidden="true">{{ strtoupper($current) }}</span>
    </summary>

    <div class="globe__panel">
        <x-locale-switch block="globe-switch" :return-to="$returnTo" />
    </div>
</details>
