@props([
    'locale',
    'fields',          // [property => ['label' => …, 'type' => 'text'|'textarea']]
    'untranslated' => [],
])
{{--
    THE SECOND LOCALE'S PANEL.

    Rendered beside the form's own fields rather than behind a tab that hides
    them, so an editor can see the English they are translating FROM while they
    type the Arabic. A tab would have made the two mutually exclusive, which is
    the one arrangement that makes translating harder than it needs to be.

    Every input carries dir and lang, so Arabic types right-to-left inside an
    otherwise left-to-right admin without any script.
--}}
<section class="form-section translation-panel"
         aria-labelledby="tr-{{ $locale }}"
         lang="{{ \App\Support\Locale::html($locale) }}">

    <h2 id="tr-{{ $locale }}" class="form-section__heading">
        {{ \App\Support\Locale::native($locale) }}
        <span class="sr-only">({{ \App\Support\Locale::name($locale) }})</span>

        @if ($untranslated !== [])
            {{--
                D2's badge, on the form as well as the list. A count rather
                than a tick: "3 fields untranslated" tells an editor whether it
                is worth opening; "incomplete" does not.
            --}}
            <span class="badge badge--warning">
                {{ count($untranslated) }} untranslated
            </span>
        @else
            <span class="badge badge--quiet">complete</span>
        @endif
    </h2>

    @foreach ($fields as $property => $meta)
        @php($isMissing = in_array($property, $untranslated, true))

        <div @class(['field', 'field--untranslated' => $isMissing])>
            <label for="tr-{{ $locale }}-{{ $property }}">
                {{ $meta['label'] }}
                @if ($isMissing)
                    <span class="field__flag">— falls back to {{ \App\Support\Locale::native(\App\Support\Locale::DEFAULT) }}</span>
                @endif
            </label>

            @if (($meta['type'] ?? 'text') === 'textarea')
                <textarea id="tr-{{ $locale }}-{{ $property }}"
                          wire:model.blur="tr.{{ $locale }}.{{ $property }}"
                          rows="{{ $meta['rows'] ?? 4 }}"
                          dir="{{ \App\Support\Locale::direction($locale) }}"
                          lang="{{ \App\Support\Locale::html($locale) }}"></textarea>
            @else
                <input type="text"
                       id="tr-{{ $locale }}-{{ $property }}"
                       wire:model.blur="tr.{{ $locale }}.{{ $property }}"
                       dir="{{ \App\Support\Locale::direction($locale) }}"
                       lang="{{ \App\Support\Locale::html($locale) }}">
            @endif

            @error('tr.'.$locale.'.'.$property)
                <p class="field__error" role="alert">{{ $message }}</p>
            @enderror
        </div>
    @endforeach
</section>
