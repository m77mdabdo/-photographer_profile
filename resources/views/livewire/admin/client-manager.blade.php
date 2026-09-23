<div>
    <header class="module-header">
        <h1 class="admin-heading">Clients</h1>
        <p class="admin-note">{{ $clients->where('is_active', true)->count() }} shown of {{ $clients->count() }}</p>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <form wire:submit="create" class="filters">
        <div class="field">
            <label for="name">Client name</label>
            <input type="text" id="name" wire:model="name" maxlength="120" required>
            @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="url">Link (optional)</label>
            <input type="url" id="url" wire:model="url" placeholder="https://">
            @error('url') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="admin-button">Add</button>
    </form>

    @if ($clients->isEmpty())
        <div class="empty-state">
            <h2>No clients yet</h2>
            <p>Names added here run beneath the hero on the home page. Order is yours to set.</p>
        </div>
    @else
        @if ($orderDirty)
            <div class="order-bar" role="status" aria-live="polite">
                <span>Order changed but not saved.</span>
                <button type="button" class="admin-button" wire:click="saveOrder">Save order</button>
            </div>
        @endif

        <ul class="category-list" role="list" data-reorderable data-reorder-handler="reorder">
            @foreach ($clients as $client)
                <li class="category-row" wire:key="client-{{ $client->id }}" data-id="{{ $client->id }}">
                    <button type="button" class="project-row__handle" data-drag-handle
                            aria-label="Reorder {{ $client->name }}. Up and down arrows move it.">⠿</button>

                    <span class="category-row__name">{{ $client->name }}</span>

                    @if ($client->url)
                        <span class="admin-note">{{ parse_url($client->url, PHP_URL_HOST) }}</span>
                    @endif

                    @if ($client->is_demo)
                        <span class="badge badge--demo">Demo</span>
                    @endif

                    <span class="badge">{{ $client->is_active ? 'Shown' : 'Hidden' }}</span>

                    <span class="category-row__actions">
                        <button type="button" class="admin-link" wire:click="toggleActive({{ $client->id }})">
                            {{ $client->is_active ? 'Hide' : 'Show' }}
                        </button>
                        <button type="button" class="admin-link" wire:click="delete({{ $client->id }})">Delete</button>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
