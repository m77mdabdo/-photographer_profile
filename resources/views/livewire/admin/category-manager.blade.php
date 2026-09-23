<div>
    <header class="module-header">
        <h1 class="admin-heading">Categories</h1>
        <p class="admin-note">{{ $categories->count() }} {{ Str::plural('category', $categories->count()) }}</p>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="admin-flash__message admin-flash__message--error" role="alert">{{ $error }}</p>
    @endif

    <form wire:submit="create" class="filters">
        <div class="field">
            <label for="name">New category</label>
            <input type="text" id="name" wire:model="name" maxlength="120" required>
            @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="admin-button">Add</button>
    </form>

    @if ($categories->isEmpty())
        <div class="empty-state">
            <h2>No categories yet</h2>
            <p>Categories group projects — Editorial, Portrait, Landscape. Add one above.</p>
        </div>
    @else
        @if ($orderDirty)
            <div class="order-bar" role="status" aria-live="polite">
                <span>Order changed but not saved.</span>
                <button type="button" class="admin-button" wire:click="saveOrder">Save order</button>
            </div>
        @endif

        <ul class="category-list" role="list" data-reorderable data-reorder-handler="reorder">
            @foreach ($categories as $category)
                <li class="category-row" wire:key="cat-{{ $category->id }}" data-id="{{ $category->id }}">
                    <button type="button" class="project-row__handle" data-drag-handle
                            aria-label="Reorder {{ $category->name }}. Use the up and down arrow keys to move it.">⠿</button>

                    @if ($editingId === $category->id)
                        <form wire:submit="rename" class="category-row__rename">
                            <label class="sr-only" for="rename-{{ $category->id }}">New name</label>
                            <input type="text" id="rename-{{ $category->id }}" wire:model="editingName" maxlength="120">
                            <button type="submit" class="admin-link">Save</button>
                            <button type="button" class="admin-link" wire:click="cancelRename">Cancel</button>
                            @error('editingName') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                        </form>
                    @else
                        <span class="category-row__name">{{ $category->name }}</span>
                        <span class="admin-note">{{ $category->slug }}</span>
                        <span class="badge">{{ $category->projects_count }} {{ Str::plural('project', $category->projects_count) }}</span>

                        <span class="category-row__actions">
                            <button type="button" class="admin-link" wire:click="startRename({{ $category->id }})">Rename</button>
                            <button type="button" class="admin-link" wire:click="delete({{ $category->id }})">Delete</button>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
