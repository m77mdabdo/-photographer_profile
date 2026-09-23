<div class="block-media">
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

    <ul class="block-media__list" role="list">
        @foreach ($block['media'] as $slot => $mediaId)
            @php($item = $mediaById->get($mediaId))
            <li class="block-media__item" wire:key="{{ $block['key'] }}-m{{ $slot }}">
                @if ($item)
                    <x-picture :media="$item" sizes="140px" img-class="block-media__image" />
                @else
                    <span class="block-media__missing">Image #{{ $mediaId }} is unavailable</span>
                @endif

                <span class="block-media__controls">
                    <button type="button" class="admin-link" wire:click="moveMedia({{ $index }}, {{ $slot }}, -1)"
                            @disabled($slot === 0) aria-label="Move image earlier">←</button>
                    <button type="button" class="admin-link" wire:click="moveMedia({{ $index }}, {{ $slot }}, 1)"
                            @disabled($slot === count($block['media']) - 1) aria-label="Move image later">→</button>
                    <button type="button" class="admin-link" wire:click="openPickerFor({{ $index }}, {{ $slot }})">Replace</button>
                    <button type="button" class="admin-link" wire:click="removeMedia({{ $index }}, {{ $slot }})">Remove</button>
                </span>
            </li>
        @endforeach
    </ul>

    {{--
        An unbounded type gets an "add" control rather than a fixed row of
        empty pickers; a bounded one only offers it while there is room.
    --}}
    @if ($type->isUnbounded() || count($block['media']) < $type->maxMedia())
        <button type="button" class="admin-button admin-button--quiet" wire:click="openPickerFor({{ $index }})">
            Add image
        </button>
    @endif
</div>
