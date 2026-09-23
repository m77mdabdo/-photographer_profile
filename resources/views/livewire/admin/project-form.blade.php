{{-- data-dirty drives the beforeunload warning; it clears the moment save succeeds. --}}
<div data-unsaved-guard data-dirty="{{ $dirty ? '1' : '0' }}">
    <p class="breadcrumb"><a href="{{ route('admin.projects.index') }}">← All projects</a></p>

    <header class="module-header">
        <h1 class="admin-heading">{{ $project?->exists ? 'Edit project' : 'New project' }}</h1>
        @if ($dirty)
            <span class="badge" role="status" aria-live="polite">Unsaved changes</span>
        @endif
    </header>

    <form wire:submit="save" class="project-form">
        <section class="form-section" aria-labelledby="sec-basic">
            <h2 id="sec-basic" class="form-section__heading">Basic information</h2>

            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" wire:model.live.debounce.500ms="title" maxlength="180" required>
                @error('title') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="slug">Slug</label>
                <input type="text" id="slug" wire:model.live.debounce.500ms="slug">
                <p class="field__hint">
                    The public address: <code>/projects/{{ $slug !== '' ? $slug : '…' }}</code>.
                    Generated from the title until you edit it.
                </p>
                @error('slug') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="subtitle">Subtitle</label>
                <input type="text" id="subtitle" wire:model.blur="subtitle" maxlength="255">
            </div>

            <div class="field">
                <label for="excerpt">Excerpt</label>
                <textarea id="excerpt" wire:model.blur="excerpt" rows="2" maxlength="500"></textarea>
                <p class="field__hint">Shown in listings and used as the meta description.</p>
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-details">
            <h2 id="sec-details" class="form-section__heading">Details</h2>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" wire:model.blur="description" rows="6"></textarea>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="year">Year</label>
                    <input type="number" id="year" wire:model.blur="year" min="1900" max="{{ date('Y') + 1 }}">
                    @error('year') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="location">Location</label>
                    <input type="text" id="location" wire:model.blur="location">
                </div>

                <div class="field">
                    <label for="client">Client</label>
                    <input type="text" id="client" wire:model.blur="client">
                </div>
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-categories">
            <h2 id="sec-categories" class="form-section__heading">Categories</h2>

            @if ($allCategories->isEmpty())
                <p class="admin-note">
                    No categories yet. <a href="{{ route('admin.categories.index') }}">Create some</a> to group projects.
                </p>
            @else
                <ul class="checkbox-list" role="list">
                    @foreach ($allCategories as $cat)
                        <li>
                            <input type="checkbox" id="cat-{{ $cat->id }}"
                                   value="{{ $cat->id }}" wire:model.live="categories">
                            <label for="cat-{{ $cat->id }}">{{ $cat->name }}</label>
                        </li>
                    @endforeach
                </ul>
                @error('categories.*') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            @endif
        </section>

        <section class="form-section" aria-labelledby="sec-media">
            <h2 id="sec-media" class="form-section__heading">Cover &amp; hero</h2>

            <div class="field-row">
                <div class="media-slot">
                    <span class="field__legend">Cover</span>

                    @if ($coverMedia)
                        <x-picture :media="$coverMedia" sizes="220px" img-class="media-slot__image" />
                        <p class="admin-note">{{ $coverMedia->original_filename }}</p>
                    @else
                        <p class="admin-note">No cover chosen.</p>
                    @endif

                    <button type="button" class="admin-button admin-button--quiet"
                            wire:click="$dispatch('open-picker', { slot: 'cover' })">
                        {{ $coverMedia ? 'Change cover' : 'Choose cover' }}
                    </button>
                    @error('cover_media_id') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="media-slot">
                    <span class="field__legend">Hero</span>

                    @if ($heroMedia)
                        <x-picture :media="$heroMedia" sizes="220px" img-class="media-slot__image" />
                        <p class="admin-note">{{ $heroMedia->original_filename }}</p>
                    @else
                        <p class="admin-note">No hero chosen.</p>
                    @endif

                    <button type="button" class="admin-button admin-button--quiet"
                            wire:click="$dispatch('open-picker', { slot: 'hero' })">
                        {{ $heroMedia ? 'Change hero' : 'Choose hero' }}
                    </button>
                    @error('hero_media_id') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-credits">
            <h2 id="sec-credits" class="form-section__heading">Credits</h2>
            <p class="admin-note">Roles vary per project. Added and reordered here, saved with the project.</p>

            @if ($credits !== [])
                <ul class="credit-list" role="list">
                    @foreach ($credits as $index => $credit)
                        <li class="credit-row" wire:key="credit-{{ $index }}">
                            <div class="field">
                                <label for="credit-role-{{ $index }}">Role</label>
                                <input type="text" id="credit-role-{{ $index }}"
                                       wire:model.blur="credits.{{ $index }}.role" maxlength="64">
                            </div>

                            <div class="field">
                                <label for="credit-name-{{ $index }}">Name</label>
                                <input type="text" id="credit-name-{{ $index }}"
                                       wire:model.blur="credits.{{ $index }}.name" maxlength="128">
                            </div>

                            <div class="credit-row__actions">
                                <button type="button" class="admin-link"
                                        wire:click="moveCredit({{ $index }}, -1)"
                                        @disabled($index === 0)
                                        aria-label="Move credit up">↑</button>

                                <button type="button" class="admin-link"
                                        wire:click="moveCredit({{ $index }}, 1)"
                                        @disabled($index === count($credits) - 1)
                                        aria-label="Move credit down">↓</button>

                                <button type="button" class="admin-link"
                                        wire:click="removeCredit({{ $index }})"
                                        aria-label="Remove credit">Remove</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <button type="button" class="admin-button admin-button--quiet" wire:click="addCredit">
                Add credit
            </button>
        </section>

        <section class="form-section" aria-labelledby="sec-publishing">
            <h2 id="sec-publishing" class="form-section__heading">Publishing</h2>

            <div class="field-row">
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" wire:model.live="status">
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="published_at">Published at</label>
                    <input type="datetime-local" id="published_at" wire:model.blur="published_at">
                    <p class="field__hint">Leave empty and publishing fills it with the moment you save. A future time schedules it.</p>
                </div>
            </div>

            <div class="field field--inline">
                <input type="checkbox" id="is_featured" wire:model.live="is_featured">
                <label for="is_featured">Featured on the home page</label>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="admin-button">Save project</button>
            <a href="{{ route('admin.projects.index') }}" class="admin-link">Cancel</a>
        </div>
    </form>

    <livewire:admin.media-picker />
</div>
