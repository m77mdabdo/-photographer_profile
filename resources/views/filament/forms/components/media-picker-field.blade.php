{{--
    The field's own surface: what is chosen now, and the ways to change it.
    The CHOOSING happens in App\Livewire\Admin\MediaPicker, mounted once per
    page by the panel provider.
--}}
@php
    $media = $getMedia();
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            /*
                The picker broadcasts to the whole page, so every field hears
                every result. `slot` carries this field's state path out and
                back, and a result for another field is ignored — otherwise
                choosing a hero would also set the cover.
            */
            receive(event) {
                const { slot, media } = event.detail ?? {}

                if (slot !== @js($statePath) || ! Array.isArray(media) || ! media.length) {
                    return
                }

                $wire.set(@js($statePath), media[0].id)
            },
        }"
        x-on:media-selected.window="receive($event)"
        class="studio-media-field"
    >
        @if ($media)
            <figure class="studio-media-field__chosen">
                <img
                    src="{{ url($media->thumbnailUrl() ?? '') }}"
                    alt=""
                    class="studio-media-field__thumb"
                    width="96"
                    height="96"
                >

                <figcaption class="studio-media-field__meta">
                    {{-- What is IN it leads; the filename is the fallback name. --}}
                    <span class="studio-media-field__alt">
                        {{ $media->getTranslation('alt', null, fallback: false)
                            ?: __('studio.media.no_alt') }}
                    </span>
                    <span class="studio-media-field__name">
                        {{ $media->original_filename }} · {{ $media->width }}×{{ $media->height }}
                    </span>
                </figcaption>
            </figure>
        @else
            <p class="studio-media-field__empty">{{ __('studio.picker.none_chosen') }}</p>
        @endif

        <div class="studio-media-field__actions">
            {{--
                wire:click rather than an Alpine $dispatch: `open-picker` is a
                LIVEWIRE event and the picker listens for it with #[On]. An
                Alpine $dispatch would raise a DOM event the picker never
                hears. This is the same call the custom admin's forms make.
            --}}
            <button
                type="button"
                class="fi-btn fi-size-sm studio-media-field__action"
                wire:click="$dispatch('open-picker', { slot: @js($statePath) })"
                @disabled($isDisabled())
            >
                {{ $media ? __('studio.picker.replace') : __('studio.picker.choose') }}
            </button>

            @if ($media && ! $isDisabled())
                <button
                    type="button"
                    class="fi-btn fi-size-sm fi-color-gray studio-media-field__action"
                    wire:click="$set(@js($statePath), null)"
                >
                    {{ __('studio.picker.clear') }}
                </button>
            @endif
        </div>
    </div>
</x-dynamic-component>
