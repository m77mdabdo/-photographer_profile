<div>
    <p class="breadcrumb"><a href="{{ route('legacy.media.index') }}">← All media</a></p>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <header class="module-header">
        <h1 class="admin-heading">Trash</h1>
        <p class="admin-note">{{ $media->total() }} {{ Str::plural('item', $media->total()) }}</p>
    </header>

    @if ($media->isEmpty())
        <div class="empty-state">
            <h2>Trash is empty</h2>
            <p>Deleted images appear here. Nothing is removed from disk until it is permanently deleted.</p>
        </div>
    @else
        <ul class="trash-list" role="list">
            @foreach ($media as $item)
                <li class="trash-row" wire:key="trash-{{ $item->id }}">
                    <div class="trash-row__main">
                        <p class="trash-row__name">{{ $item->original_filename }}</p>
                        <p class="admin-note">
                            {{ $item->width }}×{{ $item->height }} ·
                            {{ $item->variants->count() }} {{ Str::plural('rendition', $item->variants->count()) }} ·
                            deleted {{ $item->deleted_at?->diffForHumans() }}
                        </p>
                    </div>

                    <div class="trash-row__actions">
                        <button type="button" class="admin-button admin-button--quiet"
                                wire:click="restore({{ $item->id }})">
                            Restore
                        </button>

                        @can('forceDelete', $item)
                            @if ($confirmingId === $item->id)
                                {{--
                                    Typed confirmation. The button stays disabled
                                    until the text matches, so the destructive
                                    action cannot be reached by reflex.
                                --}}
                                <div class="confirm">
                                    <label for="confirm-{{ $item->id }}">
                                        Type <strong>{{ $item->original_filename }}</strong> to confirm
                                    </label>

                                    <input type="text" id="confirm-{{ $item->id }}"
                                           wire:model.live="confirmation" autocomplete="off">

                                    @error('confirmation')
                                        <p class="confirm__error" role="alert">{{ $message }}</p>
                                    @enderror

                                    <div class="confirm__actions">
                                        <button type="button" class="admin-button admin-button--danger"
                                                wire:click="forceDelete({{ $item->id }})"
                                                @disabled($confirmation !== $item->original_filename)>
                                            Delete permanently
                                        </button>

                                        <button type="button" class="admin-link" wire:click="cancelDelete">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <button type="button" class="admin-button admin-button--danger"
                                        wire:click="confirmDelete({{ $item->id }})">
                                    Delete permanently…
                                </button>
                            @endif
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>

        {{ $media->links() }}
    @endif
</div>
