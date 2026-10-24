<div>
    @if ($open)
        <div class="picker" role="dialog" aria-modal="true" aria-labelledby="picker-heading">
            <div class="picker__panel">
                <header class="picker__header">
                    <h2 id="picker-heading" class="picker__heading">
                        {{ $multiple ? __('studio.story.picker_heading_many') : __('studio.story.picker_heading_one') }}
                    </h2>
                    <button type="button" class="admin-link" wire:click="closePicker">{{ __('studio.story.picker_close') }}</button>
                </header>

                <div class="picker__controls">
                    <div class="field">
                        <label for="picker-search">{{ __('studio.story.picker_search') }}</label>
                        <input type="search" id="picker-search"
                               wire:model.live.debounce.400ms="search"
                               placeholder="{{ __('studio.story.picker_search_hint') }}" autofocus>
                    </div>

                    @if ($projectId !== null)
                        <button type="button" class="admin-link" wire:click="toggleRecent"
                                aria-pressed="{{ $recentOnly ? 'true' : 'false' }}">
                            {{ $recentOnly ? __('studio.story.picker_all') : __('studio.story.picker_recent') }}
                        </button>
                    @endif

                    @if ($multiple)
                        <button type="button" class="admin-link" wire:click="selectAllMatching">
                            {{ __('studio.story.picker_select_matching', ['count' => $matchingCount]) }}
                        </button>
                    @endif
                </div>

                @if ($multiple)
                    <p class="picker__status" role="status" aria-live="polite">
                        @if ($max !== null)
                            {{ __('studio.story.picker_selected_of', ['count' => count($selected), 'max' => $max]) }}
                        @else
                            {{ __('studio.story.picker_selected', ['count' => count($selected)]) }}
                        @endif

                        @if ($selected !== [])
                            <button type="button" class="admin-link" wire:click="clearSelection">{{ __('studio.story.picker_clear') }}</button>
                        @endif
                    </p>

                    @if ($limitMessage !== '')
                        <p class="picker__limit" role="alert">{{ $limitMessage }}</p>
                    @endif

                    <p class="admin-note">
                        {{ __('studio.story.picker_keys') }}
                        from the last one you touched.
                    </p>
                @endif

                @if ($media->isEmpty())
                    <p class="admin-note">
                        {{ __('studio.story.picker_empty') }}
                    </p>
                @else
                    <ul class="picker__grid" role="list">
                        @foreach ($media as $item)
                            @php($position = array_search($item->id, $selected, true))
                            <li wire:key="pick-{{ $item->id }}">
                                {{--
                                    A real button, so Space and Enter work with no
                                    extra key handling. Shift is read from the event
                                    so the keyboard path matches the mouse path.
                                --}}
                                <button type="button"
                                        class="picker__item @if ($position !== false) is-selected @endif"
                                        @if ($multiple)
                                            wire:click="$event.shiftKey ? $wire.extendTo({{ $item->id }}) : $wire.toggle({{ $item->id }})"
                                            aria-pressed="{{ $position !== false ? 'true' : 'false' }}"
                                        @else
                                            wire:click="choose({{ $item->id }})"
                                        @endif>
                                    @if ($multiple && $position !== false)
                                        <span class="picker__order" aria-hidden="true">{{ $position + 1 }}</span>
                                    @endif

                                    <x-picture :media="$item" sizes="160px" img-class="picker__image" />
                                    <span class="picker__name">{{ $item->original_filename }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    {{ $media->links() }}
                @endif

                <footer class="picker__footer">
                    @if ($multiple)
                        <button type="button" class="admin-button"
                                wire:click="confirmSelection"
                                @disabled($selected === [])>
                            {{ trans_choice('studio.story.picker_add', count($selected), ['count' => count($selected)]) }}
                        </button>
                    @else
                        <button type="button" class="admin-button admin-button--quiet" wire:click="clearSelection">
                            {{ __('studio.story.picker_remove_current') }}
                        </button>
                    @endif
                </footer>
            </div>
        </div>
    @endif
</div>
