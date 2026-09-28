<div data-unsaved-guard data-dirty="{{ $dirty ? '1' : '0' }}">
    <p class="breadcrumb"><a href="{{ route('admin.services.index') }}">← All services</a></p>

    <header class="module-header">
        <h1 class="admin-heading">{{ $service?->exists ? 'Edit service' : 'New service' }}</h1>
        @if ($dirty)
            <span class="badge" role="status" aria-live="polite">Unsaved changes</span>
        @endif
    </header>

    <form wire:submit="save" class="project-form">
        <section class="form-section" aria-labelledby="sec-basic">
            <h2 id="sec-basic" class="form-section__heading">The service</h2>

            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" wire:model.live.debounce.500ms="name" maxlength="120" required>
                @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="slug">Slug</label>
                <input type="text" id="slug" wire:model.live.debounce.500ms="slug">
                <p class="field__hint">
                    The public address: <code>/services/{{ $slug !== '' ? $slug : '…' }}</code>.
                    Changing it on a published service creates a permanent redirect from the old one.
                </p>
                @error('slug') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="summary">Summary</label>
                <textarea id="summary" wire:model.blur="summary" rows="2" maxlength="500"></textarea>
                <p class="field__hint">Shown on the services index and used as the meta description.</p>
                @error('summary') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-body">
            <h2 id="sec-body" class="form-section__heading">What it involves</h2>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" wire:model.live.debounce.700ms="description" rows="16"></textarea>
                <p class="field__hint">
                    Markdown. HTML typed here is removed rather than rendered — see the preview.
                </p>
                @error('description') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($preview)
                <div class="field">
                    <span class="field__legend">Preview</span>
                    <div class="richtext-preview">{!! $preview !!}</div>
                    <p class="admin-note">Exactly what will be stored and served.</p>
                </div>
            @endif
        </section>

        <section class="form-section" aria-labelledby="sec-price">
            <h2 id="sec-price" class="form-section__heading">Price</h2>
            <p class="admin-note">
                Optional. Leave it empty for work that is quoted rather than priced — the page
                then says nothing about money rather than inventing a figure.
            </p>

            <div class="field-row">
                <div class="field">
                    <label for="price_from">Starting price (£)</label>
                    <input type="text" id="price_from" wire:model.blur="price_from" inputmode="decimal"
                           placeholder="1200">
                    <p class="field__hint">A number only. The wording goes underneath.</p>
                    @error('price_from') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="price_note">What the price covers</label>
                    <input type="text" id="price_note" wire:model.blur="price_note" maxlength="120"
                           placeholder="per day, plus expenses">
                    @error('price_note') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field">
                <label for="cta_label">Button wording</label>
                <input type="text" id="cta_label" wire:model.blur="cta_label" maxlength="60"
                       placeholder="Discuss a feature">
                <p class="field__hint">Left empty, the site-wide services wording is used.</p>
                @error('cta_label') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-images">
            <h2 id="sec-images" class="form-section__heading">Images</h2>

            <div class="media-slot">
                <span class="field__legend">Cover</span>

                @if ($coverMedia)
                    <x-picture :media="$coverMedia" sizes="220px" img-class="media-slot__image" />
                    <p class="admin-note">{{ $coverMedia->original_filename }}</p>
                @else
                    <p class="admin-note">No cover chosen.</p>
                @endif

                <button type="button" class="admin-button admin-button--quiet"
                        wire:click="$dispatch('open-picker', { slot: 'service-cover' })">
                    {{ $coverMedia ? 'Change cover' : 'Choose cover' }}
                </button>
                @error('cover_media_id') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <span class="field__legend">Gallery</span>

                @if ($galleryMedia->isEmpty())
                    <p class="admin-note">No gallery images. The page runs with the cover alone.</p>
                @else
                    <ul class="category-list" role="list">
                        @foreach ($galleryMedia as $frame)
                            <li class="project-row" wire:key="gallery-{{ $frame->id }}">
                                <span class="project-row__cover">
                                    <x-picture :media="$frame" sizes="80px" img-class="project-row__thumb" />
                                </span>
                                <span class="project-row__main">
                                    <span class="project-row__meta">{{ $frame->original_filename }}</span>
                                </span>
                                <span class="project-row__actions">
                                    <button type="button" class="admin-link"
                                            wire:click="moveInGallery({{ $loop->index }}, -1)"
                                            @disabled($loop->first) aria-label="Move up">↑</button>
                                    <button type="button" class="admin-link"
                                            wire:click="moveInGallery({{ $loop->index }}, 1)"
                                            @disabled($loop->last) aria-label="Move down">↓</button>
                                    <button type="button" class="admin-link"
                                            wire:click="removeFromGallery({{ $frame->id }})">Remove</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <button type="button" class="admin-button admin-button--quiet"
                        wire:click="$dispatch('open-picker', { slot: 'service-gallery' })">
                    Add an image
                </button>
                @error('gallery') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-seo">
            <h2 id="sec-seo" class="form-section__heading">Search</h2>
            <p class="admin-note">All optional. Left empty, the name and summary above are used.</p>

            <div class="field">
                <label for="seo_title">Search title</label>
                <input type="text" id="seo_title" wire:model.blur="seo_title" maxlength="70">
                @error('seo_title') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="seo_description">Search description</label>
                <textarea id="seo_description" wire:model.blur="seo_description" rows="2" maxlength="180"></textarea>
                @error('seo_description') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="canonical_url">Canonical URL</label>
                <input type="url" id="canonical_url" wire:model.blur="canonical_url" placeholder="https://…">
                <p class="field__hint">Only if this page exists somewhere else first. Nearly always empty.</p>
                @error('canonical_url') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
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
                    @error('published_at') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="sort_order">Order</label>
                    <input type="number" id="sort_order" wire:model.blur="sort_order" min="0" max="9999">
                    @error('sort_order') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="admin-button">Save service</button>

            @if ($service?->exists && $service->isPubliclyVisible())
                <a href="{{ route('services.show', $service) }}" class="admin-link">View on the site</a>
            @endif

            <a href="{{ route('admin.services.index') }}" class="admin-link">Cancel</a>
        </div>
    </form>

    <livewire:admin.media-picker />
</div>
