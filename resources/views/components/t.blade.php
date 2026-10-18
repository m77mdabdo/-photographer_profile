@props([
    /* The model holding the value. Anything using the Translatable trait. */
    'of',

    /* The translatable attribute to render. */
    'field',

    /* The locale to render for. Defaults to the active one. */
    'locale' => null,
])
{{--
    ───────────────────────────────────────────────────────────────────────────
    TASK 36b A1 — ONE PLACE THAT RENDERS A TRANSLATABLE VALUE.

    THE BUG. On /ar/work/second-skin the project excerpt rendered as

        <p class="project__excerpt">Eighteen frames … left exactly where it was.

    with no lang and no dir, inside <html lang="ar" dir="rtl">. The full stop
    is a neutral character under the Unicode bidirectional algorithm, so it
    takes the paragraph's base direction and moves to the visual LEFT:

        .left exactly where it was

    Nothing is wrong with the text. The element simply never said which
    language it was in, so the browser applied the page's direction to English
    prose. The same class of defect as the Task 32 auth pages, arriving this
    time through the translation fallback rather than through a template.

    WHY A COMPONENT AND NOT A FIX PER TEMPLATE. The decision — did this value
    fall back, and from where — is made ONCE, in
    Translatable::translationLocale(). This is the single thing that turns
    that answer into markup. A template that renders a translatable attribute
    uses this; a template that prints one directly is the regression, and
    tests/Feature/FallbackAnnotationTest.php scans the rendered pages for
    exactly that rather than trusting every author to remember.

    THE TRANSLATED CASE EMITS NOTHING EXTRA. When the value is genuinely in
    the active locale the output is the bare text, byte for byte what it was
    before — so every pinned byte count and every existing assertion still
    describes the same markup. The wrapper appears only where there is a
    fallback to declare.

    dir ALONE WOULD NOT BE ENOUGH, AND NOR WOULD lang. dir gives the browser
    the bidi isolate that puts the punctuation back; lang is what a screen
    reader reads to choose a voice, and an English sentence announced by an
    Arabic voice is the same defect in the audio channel.

    THE OUTPUT IS ECHOED FROM INSIDE THE PHP BLOCK, WHICH IS ABOUT
    WHITESPACE AND NOTHING ELSE. Three attempts, each measured:

      1. An escaped echo wrapped in a conditional — the idiomatic shape, and
         wrong here. Blade emitted a trailing newline, so the UNTRANSLATED
         case stopped being byte-identical to the bare attribute echo it
         replaces. HTML collapses that newline to a space, which moves inline
         elements (a span in a flex row gains a gap) and would have shifted
         every byte count this suite pins.
      2. Building the string and echoing it unescaped on the closing line.
         That did not compile: Blade lifts unescaped echoes out as raw blocks
         before it compiles a PHP block, and one placed immediately after the
         closing directive came back as the literal text "@__raw_block_0__".
      3. Echoing from inside the PHP block, where the bytes are exactly what
         is written and nothing is lifted. This one.

    Two things learned the hard way and worth leaving written down: the
    unescaped-echo braces are extracted even from inside a comment, and the
    closing PHP directive is honoured even inside a PHP comment — each broke
    an attempt above. The value is escaped with e() on every branch, which is
    what makes echoing raw markup safe in this one file; asserted in
    FallbackAnnotationTest with a value containing a script tag.

    SYMMETRIC, THOUGH THE FALLBACK IS NOT. Arabic can never fall back onto an
    English page — en is the default locale, so the rule runs out at it and
    returns null, measured — but this emits lang="ar" dir="rtl" just as
    readily for an Arabic value rendered in an English page, which is what a
    deliberately Arabic proper noun needs.

    AND THAT CASE FOUND A BUG IN THIS FILE. The first version compared the
    value's locale with the locale it was ASKED for, which are the same thing
    in a fallback and not the same thing anywhere else: a caller passing
    locale="ar" on an English page got from === locale and therefore no
    annotation, so Arabic prose rendered inside a left-to-right document with
    nothing to say so. The comparison is against the DOCUMENT's locale now.
    One parameter says which translation to fetch; the page says what needs
    declaring.
--}}
@php
    /*
     * Two different locales, and conflating them was a bug. $locale picks
     * WHICH translation to fetch; App\Support\Fallback measures against the
     * DOCUMENT's locale, which is what needs declaring. See the note above.
     */
    $value = $of->getTranslation($field, $locale);
    $from = \App\Support\Fallback::from($of, $field, $locale);

    echo match (true) {
        $value === null || $value === '' => '',
        /*
         * TASK 36b — a value that IS in the page's language can still contain
         * a foreign name, an acronym or an address. isolateForeign wraps those
         * runs; it returns the value untouched on a left-to-right page and for
         * a value that is mostly Latin, which the element already declares.
         */
        $from === null => e(\App\Support\Fallback::isolateForeign($value)),
        default => sprintf(
            '<span lang="%s" dir="%s">%s</span>',
            e(\App\Support\Locale::html($from)),
            e(\App\Support\Locale::direction($from)),
            e($value),
        ),
    };
@endphp