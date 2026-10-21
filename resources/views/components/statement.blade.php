{{--
    The scrubbed statement — Task 38 Part B. Behaviour in
    resources/js/public/motion/statement.js; why each number is what it is,
    there and in tokens.css.

    What the server sends is a plain paragraph at full contrast. Everything
    the effect does is done by the script to a paragraph that already reads.

    AT MOST ONE PER SECTION, AND NEVER IN TWO SECTIONS IN A ROW (Task 41 5.1).
    Task 38 allowed one per page; the recordings carry one per major section
    of a long page, three inside thirteen seconds of scrolling. The rule is
    held by where the templates place a statement, and StatementTest walks
    every public page and asserts it on the rendered DOM.

    Any element: `tag` defaults to a paragraph, and the home page's closing
    line is its h2.

    The effect runs in resources/js/public/arrive (Task 41 Part 5), on every
    page view, without GSAP.

    The accent rule (B4) is the owner's: home.statement_accent, per locale —
    "last" or "none".
--}}
@props(['tag' => 'p'])
@php
    // A statement is something read: a paragraph, a heading, a quotation.
    $tag = in_array($tag, ['p', 'h2', 'h3', 'blockquote'], true) ? $tag : 'p';
    $accent = \App\Support\SiteCopy::get('home.statement_accent') === 'last';
@endphp
<{{ $tag }} {{ $attributes->class(['statement']) }} data-arrive="statement"@if ($accent) data-accent="last"@endif>{{ $slot }}</{{ $tag }}>
