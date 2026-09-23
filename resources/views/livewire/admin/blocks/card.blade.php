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
    data-max="{{ $type->maxMedia() ?? '' }}">

    <header class="block-card__header">
        <button type="button" class="project-row__handle" data-drag-handle
                aria-label="Reorder {{ $type->label() }} block. Up and down arrows move it.">⠿</button>

        <button type="button" class="block-card__toggle" data-collapse-toggle aria-expanded="true">
            <span class="block-card__chevron" aria-hidden="true">▾</span>
            <span class="block-card__type">{{ $type->label() }}</span>
        </button>

        <span class="block-card__summary" data-summary></span>

        <span class="block-card__actions">
            <button type="button" class="admin-link" data-move="-1" aria-label="Move block up">↑</button>
            <button type="button" class="admin-link" data-move="1" aria-label="Move block down">↓</button>
            <button type="button" class="admin-link" data-duplicate>Duplicate</button>
            <button type="button" class="admin-link" data-remove>Remove</button>
        </span>
    </header>

    <div class="block-card__confirm" data-confirm hidden>
        <p class="admin-note">Remove this {{ $type->label() }} block? Its images stay in the library.</p>
        <button type="button" class="admin-button admin-button--danger" data-remove-confirm>Yes, remove it</button>
        <button type="button" class="admin-link" data-remove-cancel>Cancel</button>
    </div>

    <div class="block-card__body" data-body>
        @if ($type->acceptsMedia())
            <div class="block-media" data-media-zone>
                <p class="field__legend">
                    Images
                    <span class="admin-note">
                        @if ($type->isUnbounded())
                            {{ $type->minMedia() }} or more
                        @elseif ($type->minMedia() === $type->maxMedia())
                            exactly {{ $type->minMedia() }}
                        @else
                            {{ $type->minMedia() }}–{{ $type->maxMedia() }}
                        @endif
                    </span>
                </p>

                <ul class="block-media__list" role="list" data-media-items>
                    @foreach ($block['media'] ?? [] as $slot => $mediaId)
                        @php($item = $mediaById->get($mediaId))
                        <li class="block-media__item" data-media-item data-media-id="{{ $mediaId }}">
                            @if ($item && $item->thumbnailUrl())
                                <img src="{{ $item->thumbnailUrl() }}" alt="" class="block-media__image" loading="lazy">
                            @else
                                <span class="block-media__missing">#{{ $mediaId }}</span>
                            @endif

                            <label class="block-media__alt">
                                <span class="sr-only">Alt text for this image</span>
                                <input type="text" data-description
                                       value="{{ $descriptions[$slot] ?? '' }}"
                                       placeholder="{{ $item?->alt ? 'Using: '.$item->alt : 'Describe this image' }}">
                            </label>

                            <span class="block-media__controls">
                                <button type="button" class="admin-link" data-media-move="-1" aria-label="Move image earlier">←</button>
                                <button type="button" class="admin-link" data-media-move="1" aria-label="Move image later">→</button>
                                <button type="button" class="admin-link" data-media-remove aria-label="Remove image">×</button>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <button type="button" class="admin-button admin-button--quiet" data-add-media>Add images</button>
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
