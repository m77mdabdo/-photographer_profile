@props(['record'])
@php
    use App\Support\Locale;

    /*
     * D2 — THE UNTRANSLATED STATE, VISIBLE IN A LIST.
     *
     * The public fallback makes a half-translated site readable, which is
     * right, and which also means an editor looking at the Arabic site cannot
     * tell translated from untranslated by looking at it. Everything renders.
     *
     * So the admin says so explicitly, per locale, with a count rather than a
     * tick: "4 untranslated" tells somebody whether the record is worth
     * opening; a red dot does not.
     *
     * A record with nothing to translate — every translatable field empty in
     * every locale — shows nothing at all, so the badge stays meaningful
     * instead of appearing on everything.
     */
    $states = [];

    foreach (Locale::codes() as $code) {
        if ($code === Locale::DEFAULT) {
            continue;
        }

        $missing = $record->untranslatedAttributes($code);

        $states[$code] = $missing;
    }
@endphp
@foreach ($states as $code => $missing)
    @if ($missing !== [])
        <span class="badge badge--warning"
              title="{{ Locale::name($code) }}: {{ implode(', ', $missing) }}">
            <span lang="{{ Locale::html($code) }}">{{ Locale::native($code) }}</span>
            — {{ count($missing) }} untranslated
        </span>
    @else
        <span class="badge badge--quiet">
            <span lang="{{ Locale::html($code) }}">{{ Locale::native($code) }}</span> ✓
        </span>
    @endif
@endforeach
