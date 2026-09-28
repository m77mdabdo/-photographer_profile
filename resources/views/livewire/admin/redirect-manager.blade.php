<div>
    <header class="module-header">
        <h1 class="admin-heading">Redirects</h1>
        <p class="admin-note">{{ $redirects->total() }} {{ Str::plural('redirect', $redirects->total()) }}</p>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <section class="form-section" aria-labelledby="sec-add">
        <h2 id="sec-add" class="form-section__heading">Add a redirect</h2>
        <p class="admin-note">
            Renaming a published project, article or service creates one of these automatically.
            Add one here when an address changed somewhere else — an old site, a printed card.
        </p>

        <form wire:submit="add" class="filters">
            <div class="field">
                <label for="from_path">From</label>
                <input type="text" id="from_path" wire:model="from_path" placeholder="/old-address">
                @error('from_path') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="to_path">To</label>
                <input type="text" id="to_path" wire:model="to_path" placeholder="/work/new-address">
                @error('to_path') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="status_code">Kind</label>
                <select id="status_code" wire:model="status_code">
                    <option value="301">301 — permanent</option>
                    <option value="302">302 — temporary</option>
                </select>
                @error('status_code') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="admin-button">Add</button>
        </form>
    </section>

    <div class="filters">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" wire:model.live.debounce.400ms="search" placeholder="Either address">
        </div>

        <div class="field">
            <label for="source">Origin</label>
            <select id="source" wire:model.live="source">
                <option value="">Any</option>
                <option value="manual">Added by hand</option>
                <option value="slug_change">Created by a rename</option>
            </select>
        </div>
    </div>

    @if ($redirects->isEmpty())
        <div class="empty-state">
            <h2>Nothing here</h2>
            <p>No addresses have moved yet.</p>
        </div>
    @else
        <ul class="category-list" role="list">
            @foreach ($redirects as $redirect)
                <li class="category-row" wire:key="redirect-{{ $redirect->id }}">
                    <span class="project-row__main">
                        <span class="project-row__title">
                            {{ $redirect->from_path }} → {{ $redirect->to_path }}
                        </span>

                        <span class="project-row__meta">
                            {{ $redirect->status_code }}
                            · {{ $redirect->source === 'slug_change' ? 'created by a rename' : 'added by hand' }}
                            · {{ $redirect->hits }} {{ Str::plural('hit', $redirect->hits) }}
                            @if ($redirect->last_hit_at) · last {{ $redirect->last_hit_at->diffForHumans() }} @endif
                        </span>

                        {{--
                            When a chain has been flattened, say so. An editor
                            looking at "/a → /b" while the server actually sends
                            people to /c has no way to know without this.
                        --}}
                        @php($resolvedTo = $resolved[$redirect->from_path][0] ?? null)
                        @if ($redirect->is_active && $resolvedTo !== null && $resolvedTo !== $redirect->to_path)
                            <span class="project-row__meta">
                                Follows a chain — visitors are sent to <strong>{{ $resolvedTo }}</strong>.
                            </span>
                        @elseif ($redirect->is_active && $resolvedTo === null)
                            <span class="project-row__meta">
                                <strong>Not served.</strong> This forms a loop, or a chain too long to follow.
                            </span>
                        @endif
                    </span>

                    <span class="category-row__actions">
                        <span class="badge badge--{{ $redirect->is_active ? 'published' : 'draft' }}">
                            {{ $redirect->is_active ? 'Active' : 'Off' }}
                        </span>

                        <button type="button" class="admin-link" wire:click="toggle({{ $redirect->id }})">
                            {{ $redirect->is_active ? 'Switch off' : 'Switch on' }}
                        </button>

                        <button type="button" class="admin-link"
                                wire:click="delete({{ $redirect->id }})"
                                wire:confirm="Remove this redirect? Anyone still using the old address will get a 404.">
                            Remove
                        </button>
                    </span>
                </li>
            @endforeach
        </ul>

        {{ $redirects->links() }}
    @endif
</div>
