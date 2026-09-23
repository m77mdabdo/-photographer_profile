<div data-unsaved-guard data-dirty="{{ $dirty ? '1' : '0' }}">
    <p class="breadcrumb">
        <a href="{{ route('admin.projects.edit', $project) }}">← {{ $project->title }}</a>
    </p>

    <header class="module-header">
        <h1 class="admin-heading">Story</h1>
        <p class="admin-note">{{ count($blocks) }} {{ Str::plural('block', count($blocks)) }}</p>

        @if ($dirty)
            <span class="badge" role="status" aria-live="polite">Unsaved changes</span>
        @endif

        <a href="{{ route('admin.projects.preview', $project) }}" class="admin-link" target="_blank" rel="noopener">
            Preview
        </a>

        <button type="button" class="admin-button" wire:click="save">Save story</button>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    @error('story')
        <p class="admin-flash__message admin-flash__message--error" role="alert">{{ $message }}</p>
    @enderror

    {{-- Type picker, grouped so the list is readable at fourteen types. --}}
    <section class="type-picker" aria-labelledby="add-block-heading">
        <h2 id="add-block-heading" class="form-section__heading">Add a block</h2>

        @foreach ($types as $group => $cases)
            <div class="type-picker__group">
                <p class="type-picker__label">{{ $group }}</p>
                <ul class="type-picker__list" role="list">
                    @foreach ($cases as $case)
                        <li>
                            <button type="button" class="admin-button admin-button--quiet"
                                    wire:click="addBlock('{{ $case->value }}')">
                                {{ $case->label() }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </section>

    @if ($blocks === [])
        <div class="empty-state">
            <h2>This story is empty</h2>
            <p>Add a block above. Blocks stack down the page in the order you arrange them here.</p>
        </div>
    @else
        <ul class="block-list" role="list" data-reorderable data-reorder-handler="reorderBlocks">
            @foreach ($blocks as $index => $block)
                @php($type = \App\Enums\BlockType::from($block['type']))
                <li class="block-card @if (isset($blockErrors[$index])) block-card--invalid @endif"
                    wire:key="{{ $block['key'] }}"
                    data-id="{{ $block['key'] }}">

                    <header class="block-card__header">
                        <button type="button" class="project-row__handle" data-drag-handle
                                aria-label="Reorder {{ $type->label() }} block. Use the up and down arrow keys to move it.">⠿</button>

                        <span class="block-card__type">{{ $type->label() }}</span>
                        <span class="block-card__position">{{ $index + 1 }} of {{ count($blocks) }}</span>

                        <span class="block-card__actions">
                            <button type="button" class="admin-link" wire:click="moveBlock({{ $index }}, -1)"
                                    @disabled($index === 0) aria-label="Move block up">↑</button>
                            <button type="button" class="admin-link" wire:click="moveBlock({{ $index }}, 1)"
                                    @disabled($index === count($blocks) - 1) aria-label="Move block down">↓</button>
                            <button type="button" class="admin-link" wire:click="duplicateBlock({{ $index }})">Duplicate</button>
                            <button type="button" class="admin-link" wire:click="confirmRemove({{ $index }})">Remove</button>
                        </span>
                    </header>

                    @if ($confirmingRemoval === $index)
                        <div class="danger__body" role="alert">
                            <p class="admin-note">Remove this {{ $type->label() }} block? Its images stay in the library.</p>
                            <button type="button" class="admin-button admin-button--danger"
                                    wire:click="removeBlock({{ $index }})">Yes, remove it</button>
                            <button type="button" class="admin-link" wire:click="cancelRemove">Cancel</button>
                        </div>
                    @endif

                    {{-- Problems sit with the block they belong to. --}}
                    @if (isset($blockErrors[$index]))
                        <ul class="block-card__errors" role="alert">
                            @foreach ($blockErrors[$index] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="block-card__body">
                        @if ($type->acceptsMedia())
                            @include('livewire.admin.blocks.media', [
                                'index' => $index,
                                'block' => $block,
                                'type' => $type,
                                'mediaById' => $mediaById,
                            ])
                        @endif

                        @if ($type->hasText())
                            @include('livewire.admin.blocks.fields', [
                                'index' => $index,
                                'block' => $block,
                                'type' => $type,
                            ])
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="form-actions">
            <button type="button" class="admin-button" wire:click="save">Save story</button>
        </div>
    @endif

    <livewire:admin.media-picker />
</div>
