<div>
    @if ($open)
        {{--
            A dialog in markup rather than a JS modal: it is reachable by
            keyboard, announced as a dialog, and works if scripts fail to load.
        --}}
        <div class="picker" role="dialog" aria-modal="true" aria-labelledby="picker-heading">
            <div class="picker__panel">
                <header class="picker__header">
                    <h2 id="picker-heading" class="picker__heading">Choose an image</h2>
                    <button type="button" class="admin-link" wire:click="closePicker">Close</button>
                </header>

                <div class="field">
                    <label for="picker-search">Search</label>
                    <input type="search" id="picker-search"
                           wire:model.live.debounce.400ms="search"
                           placeholder="Filename, alt text or credit" autofocus>
                </div>

                @if ($media->isEmpty())
                    <p class="admin-note">
                        No processed images match. Only images that finished processing can be used.
                    </p>
                @else
                    <ul class="picker__grid" role="list">
                        @foreach ($media as $item)
                            <li wire:key="pick-{{ $item->id }}">
                                <button type="button" class="picker__item"
                                        wire:click="choose({{ $item->id }})">
                                    <x-picture :media="$item" sizes="160px" img-class="picker__image" />
                                    <span class="picker__name">{{ $item->original_filename }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    {{ $media->links() }}
                @endif

                <footer class="picker__footer">
                    <button type="button" class="admin-button admin-button--quiet"
                            wire:click="clearSelection">
                        Remove current image
                    </button>
                </footer>
            </div>
        </div>
    @endif
</div>
