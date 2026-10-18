@props([
    /* The models to render, each using the Translatable trait. */
    'items',

    /* The translatable attribute to render on each. */
    'field' => 'name',

    /* What goes between them. */
    'separator' => ' · ',
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
    through <x-t> like every other translatable value.

    The separator is plain text and deliberately outside the items: it belongs
    to the page, not to either language.
    ───────────────────────────────────────────────────────────────────────────
--}}
@php
    $rendered = collect($items)->filter()->values();
@endphp
@foreach ($rendered as $index => $item)
<x-t :of="$item" :field="$field" />{{ $index + 1 < $rendered->count() ? $separator : '' }}
@endforeach
