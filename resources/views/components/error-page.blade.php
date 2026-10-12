@props(['code'])
{{--
    ONE ERROR PAGE, SIX ADDRESSES.

    403, 404, 419, 429, 500 and 503 were six views containing the same four
    elements in the same order with different words — and the words were typed
    into each of them, so an owner could not change any of the twenty-four
    strings, and a change to the LAYOUT of an error page meant six edits.

    The code is the only thing that varies structurally. Everything else comes
    from App\Support\ErrorCopy, which owns the mapping from a code to its two
    settings keys and their fallbacks.

    noindex on every one: an error page in a search index is a broken result
    for somebody else.
--}}
@php
    $heading = \App\Support\ErrorCopy::heading($code);
    $body = \App\Support\ErrorCopy::body($code);
@endphp

<x-layouts::public :seo="\App\Support\SeoData::make(
    title: $heading,
    description: $body,
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        {{-- The number stays a Western numeral in both locales: it is an HTTP
             status, not prose. See docs/decisions/0005-numerals.md. --}}
        <p class="error-page__code">{{ \App\Support\ErrorCopy::prefix() }} {{ $code }}</p>

        <h1 class="error-page__title">{{ $heading }}</h1>

        <p class="error-page__body">{{ $body }}</p>

        <a class="error-page__home" href="{{ route('home') }}">
            {{ \App\Support\ErrorCopy::returnHome() }}
        </a>
    </section>
</x-layouts::public>
