@php
    /** @var \App\Enums\BlockType $type */
    $blockData = $block['data'] ?? [];
    $descriptions = (array) ($blockData['descriptions'] ?? []);
@endphp
<li class="block-card"
    data-block
    data-key="{{ $block['key'] }}"
    data-id="{{ $block['id'] ?? '' }}"
    data-type="{{ $type->value }}"
    data-min="{{ $type->minMedia() }}"
    data-max="{{ $type->maxMedia() ?? '' }}"
    {{-- Rules rendered straight from the enum, so the browser's live checks
         cannot drift from the server's. --}}
    data-required="{{ implode(',', $type->requiredDataKeys()) }}"
    data-label="{{ $type->label() }}">

    <header class="block-card__header">
        <button type="button" class="project-row__handle" data-drag-handle
                aria-label="Reorder {{ $type->label() }} block. Up and down arrows move it.">⠿</button>

        <button type="button" class="block-card__toggle" data-collapse-toggle aria-expanded="true">
            <span class="block-card__chevron" aria-hidden="true">▾</span>
            <span class="block-card__type">{{ $type->label() }}</span>
        </button>

        <span class="block-card__summary" data-summary></span>
        <span class="block-card__flag" data-flag hidden aria-hidden="true">!</span>

        <span class="block-card__actions">
            <button type="button" class="admin-link" data-move="-1" aria-label="{{ __('studio.story.move_up') }}">↑</button>
            <button type="button" class="admin-link" data-move="1" aria-label="{{ __('studio.story.move_down') }}">↓</button>
            <button type="button" class="admin-link" data-duplicate>{{ __('studio.story.duplicate') }}</button>
            <button type="button" class="admin-link" data-remove>{{ __('studio.story.remove') }}</button>
        </span>
    </header>

    <div class="block-card__confirm" data-confirm hidden>
        <p class="admin-note">Remove this {{ $type->label() }} block? Its images stay in the library.</p>
        <button type="button" class="admin-button admin-button--danger" data-remove-confirm>{{ __('studio.story.confirm_remove') }}</button>
        <button type="button" class="admin-link" data-remove-cancel>{{ __('studio.story.cancel') }}</button>
    </div>

    <ul class="block-card__errors" data-errors role="alert" hidden></ul>

    <div class="block-card__body" data-body>
        @if ($type->acceptsMedia())
            <div class="block-media" data-media-zone>
                <p class="field__legend">
                    {{ __('studio.story.images_legend') }}
                    <span class="admin-note">
                        @if ($type->isUnbounded())
                            {{ __('studio.story.count_or_more', ['count' => $type->minMedia()]) }}
                        @elseif ($type->minMedia() === $type->maxMedia())
                            {{ __('studio.story.count_exactly', ['count' => $type->minMedia()]) }}
                        @else
                            {{ $type->minMedia() }}–{{ $type->maxMedia() }}
                        @endif
                    </span>
                </p>

                <ul class="block-media__list" role="list" data-media-items>
                    @foreach ($block['media'] ?? [] as $slot => $mediaId)
                        @php($item = $mediaById->get($mediaId))
                        <li class="block-media__item" data-media-item data-media-id="{{ $mediaId }}" tabindex="0">
                            @if ($item && $item->thumbnailUrl())
                                <img src="{{ $item->thumbnailUrl() }}" alt="" class="block-media__image" loading="lazy">
                            @else
                                <span class="block-media__missing">#{{ $mediaId }}</span>
                            @endif

                            <label class="block-media__alt">
                                <span class="sr-only">{{ __('studio.story.alt_for_image') }}</span>
                                <input type="text" data-description
                                       value="{{ $descriptions[$slot] ?? '' }}"
                                       placeholder="{{ $item?->alt ? 'Using: '.$item->alt : 'Describe this image' }}">
                            </label>

                            <span class="block-media__controls">
                                <button type="button" class="admin-link" data-media-move="-1" aria-label="{{ __('studio.story.move_image_earlier') }}">←</button>
                                <button type="button" class="admin-link" data-media-move="1" aria-label="{{ __('studio.story.move_image_later') }}">→</button>
                                <button type="button" class="admin-link" data-media-remove aria-label="{{ __('studio.story.remove_image') }}">×</button>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <button type="button" class="admin-button admin-button--quiet" data-add-media>{{ __('studio.story.add_images') }}</button>
            </div>
        @endif

        @if ($type->hasText())
            <div class="block-fields">
                @foreach ($type->allowedDataKeys() as $key)
                    @continue($key === 'descriptions')
                    <div class="field">
                        <label>{{ Str::headline($key) }}
                            @if (in_array($key, ['body', 'quote', 'caption'], true))
                                <textarea rows="{{ $key === 'body' ? 5 : 2 }}" data-field="{{ $key }}">{{ $blockData[$key] ?? '' }}</textarea>
                            @elseif ($key === 'height')
                                <input type="number" min="0" max="600" step="8" data-field="{{ $key }}" value="{{ $blockData[$key] ?? '' }}">
                            @elseif ($key === 'url')
                                <input type="url" inputmode="url" data-field="{{ $key }}" value="{{ $blockData[$key] ?? '' }}">
                            @else
                                <input type="text" data-field="{{ $key }}" value="{{ $blockData[$key] ?? '' }}">
                            @endif
                        </label>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</li>
