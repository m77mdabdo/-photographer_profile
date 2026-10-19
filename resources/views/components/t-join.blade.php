@props([
    /* The models to render, each using the Translatable trait. */
    'items',

    /* The translatable attribute to render on each. */
    'field' => 'name',

    /*
     * WHICH MARK GOES BETWEEN THEM, BY NAME AND NEVER AS A LITERAL.
     *
     * This was `separator => ' · '` and two call sites passed `", "`. A
     * template cannot be allowed to type the character, because the correct
     * character depends on the locale of the run it lands in and a template
     * does not know that locale — resolving it is this component's whole job.
     * App\Support\Locale::separator() holds the two marks and says which of
     * them is language and which is design.
     */
    'separator' => 'middot',
])
{{--
    ───────────────────────────────────────────────────────────────────────────
    TASK 36b B3 — A LIST OF TRANSLATABLE NAMES, EACH ANSWERING FOR ITSELF.

    Four templates printed a project's categories as

        {{ $project->categories->pluck('name')->implode(' · ') }}

    which collapses the whole list into one string before anything can ask a
    question about any of it. Each category falls back independently — a site
    mid-translation has "تصنيف · Documentary" — and pluck() leaves no element
    to carry lang and dir on the half that fell back. Found by
    FallbackAnnotationTest, which reported "Documentary" undeclared on the
    home page, the work index, the archive and every project page at once.

    So the join happens in markup rather than in a string, and each item goes
    through App\Support\Fallback like every other translatable value.

    ═══════════════════════════════════════════════════════════════════════════
    TASK 38 0.1 — AND THE SENTENCE THAT USED TO BE HERE WAS WRONG.

    It read: "The separator is plain text and deliberately outside the items:
    it belongs to the page, not to either language." The Task 37b audit then
    reported the result as a bug — `FASHION ,EDITORIAL` on /ar — and the
    diagnosis in that report was ALSO wrong. Both are worth writing down,
    because the measurement that settled it contradicted me twice.

    WHAT I CLAIMED IN 37b: the separator "sits outside the isolates as a
    neutral in RTL and lands on the wrong side."

    WHAT THE BROWSER ACTUALLY DOES. Measured on the real /ar at 1440, and in
    a matrix of four item combinations × three marks × four candidate fixes:
    the separator's position is CORRECT in every case, including the code as
    it shipped. An element carrying `dir` is an isolate under the UA
    stylesheet, so the paragraph sees three neutrals; all three take the
    paragraph's RTL direction; the first item lands rightmost and the mark
    lands immediately to its left — which, read right-to-left, is exactly
    "item one, mark, item two". Correct Arabic list typography.

    I had read `Fashion ,Editorial` as a Latin reader and called correct bidi
    a defect. The mark was never misplaced.

    AND THE OBVIOUS FIX WOULD HAVE BROKEN IT. Moving the separator inside the
    preceding item's isolate — the two-line change, candidate B in the matrix
    — gives `item2 item1 SEP` for two Latin items and for en-then-ar: the
    mark migrates to the end of the whole list. It is right for ar+ar and
    wrong for half the cases, which is why the measurement ran before the
    edit and not after.

    SO WHAT WAS ACTUALLY WRONG — two things, both real, neither the one I
    reported:

      1. THE GLYPH. `, ` is U+002C, a Latin comma, rendered in Arabic prose.
         Arabic uses U+060C, which is raised and mirrored. A Latin comma there
         is the same class of error as a Latin question mark.

      2. THE ORDER, WHEN EVERY ITEM FELL BACK. Two English names in an Arabic
         paragraph are laid out right-to-left, so an English reader meets the
         list backwards: logical "Editorial, Fashion" renders as
         `Fashion , Editorial`. The list is English; the paragraph is not; and
         the paragraph won.

    THE RULE, THEREFORE: THE SEPARATOR TAKES THE DIRECTION OF THE RUN IT SITS
    IN, AND THE RUN IS DECIDED BY THE ITEMS.

      ALL ITEMS IN ONE LOCALE THAT IS NOT THE PAGE'S — the whole list is a run
      of that locale. It is wrapped once in <span lang dir>, which makes it a
      single isolate laid out in its own direction, and the separator is that
      locale's mark, inside the wrapper. Two fallen-back English names read
      "Editorial, Fashion" left to right with a Latin comma, inside an Arabic
      page.

      THE ONLY RUN REACHABLE THROUGH THIS COMPONENT TODAY IS en ON /ar, and
      saying so is more useful than the symmetry it looks like. English is the
      default locale, so the fallback rule runs out at it and an Arabic value
      can never stand in for a missing English one — measured in 36b and
      written down in <x-t>. An Arabic-only name on an English page therefore
      renders as nothing at all, not as an Arabic run, and this component
      takes no `locale` prop with which a caller could ask for one.

      The rule below is still written as "the items' locale, whatever it is"
      rather than as "English", because the day a third locale or a locale
      prop arrives the branch should already be right. What is NOT claimed is
      that the mirror case has been seen: it has not, and there is no test for
      it, because there is no way to reach it.

      EVERYTHING ELSE — the list belongs to the page. Page direction, page's
      mark, each item declaring itself through Fallback::render().

    ─── AND "EVERYTHING ELSE" IS MOSTLY ONE CASE: A MIXED LIST. ───

    One Arabic name and one English name — a site halfway through being
    translated, which is the normal state and the state the fallback exists
    for. There is no run for the list to belong to, because the items disagree,
    so it belongs to the page: on /ar the mark is U+060C and the first item is
    rightmost; on / the mark is U+002C and the first item is leftmost. The
    English name inside an Arabic list still carries its own lang="en"
    dir="ltr" and is still isolated, so its own internal order is right and a
    screen reader still changes voice for it.

    THIS IS THE CASE A TWO-LINE FIX GETS WRONG, and it is why the branch is on
    "do the items AGREE" rather than on "did anything fall back". A list where
    one of three names is translated is not a foreign run and must not be
    wrapped as one — wrapping it would force the Arabic name into a
    left-to-right box and move ITS punctuation, trading one defect for the
    same defect one level down.

    WHAT A SINGLE-ITEM LIST EMITS IS UNCHANGED, BYTE FOR BYTE, in both
    branches: one translated item is a bare escaped string, one fallen-back
    item is <span lang dir>…</span>. That is deliberate — the pinned byte
    counts in this suite describe those two shapes — and asserted in
    TranslatableJoinTest.
    ═══════════════════════════════════════════════════════════════════════════
--}}
@php
    use App\Support\Fallback;
    use App\Support\Locale;

    $rendered = collect($items)->filter()->values();

    $page = Locale::current();

    /*
     * The locale each item will actually be rendered in: its fallback source
     * where it fell back, the page's otherwise. Asked BEFORE anything is
     * rendered, because the answer decides how all of it is rendered.
     */
    $sources = $rendered->map(fn ($item): string => Fallback::from($item, $field) ?? $page);

    /* One locale for the whole list, and not the page's? Then it is a run. */
    $run = $sources->unique()->count() === 1 && $sources->first() !== $page
        ? $sources->first()
        : null;

    $mark = e(Locale::separator($separator, $run ?? $page));

    $body = $rendered
        ->map(fn ($item): string => Fallback::render($item, $field, declare: $run === null))
        ->filter(fn (string $html): bool => $html !== '')
        ->implode($mark);

    echo match (true) {
        $body === '' => '',
        $run === null => $body,
        default => sprintf(
            '<span lang="%s" dir="%s">%s</span>',
            e(Locale::html($run)),
            e(Locale::direction($run)),
            $body,
        ),
    };
@endphp
