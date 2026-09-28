<div>
    <header class="module-header">
        <h1 class="admin-heading">Pages</h1>
        <p class="admin-note">{{ $pages->count() }} {{ Str::plural('page', $pages->count()) }}</p>
        <button type="button" class="admin-button" wire:click="create">New page</button>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <div class="detail">
        <div>
            @if ($editing === '')
                <div class="empty-state">
                    <h2>Choose a page</h2>
                    <p>Privacy and terms are linked from the footer of every page and cannot be removed.</p>
                </div>
            @else
                <form wire:submit="save" class="project-form">
                    <section class="form-section" aria-labelledby="sec-page">
                        <h2 id="sec-page" class="form-section__heading">
                            {{ $current ? 'Edit page' : 'New page' }}
                        </h2>

                        <div class="field">
                            <label for="title">Title</label>
                            <input type="text" id="title" wire:model.blur="title" maxlength="160" required>
                            @error('title') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div class="field">
                            <label for="slug">Address</label>
                            <input type="text" id="slug" wire:model.blur="slug" @disabled($current?->is_locked)>
                            <p class="field__hint">
                                @if ($current?->is_locked)
                                    <strong>Fixed.</strong> The footer links to this page by address, so moving it
                                    would leave that link pointing at a page that does not exist.
                                @else
                                    The public address: <code>/{{ $slug !== '' ? $slug : '…' }}</code>.
                                @endif
                            </p>
                            @error('slug') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div class="field">
                            <label for="standfirst">Standfirst</label>
                            <textarea id="standfirst" wire:model.blur="standfirst" rows="2" maxlength="300"></textarea>
                            @error('standfirst') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div class="field">
                            <label for="body">Body</label>
                            <textarea id="body" wire:model.live.debounce.700ms="body" rows="20"></textarea>
                            <p class="field__hint">Markdown. HTML typed here is removed rather than rendered.</p>
                            @error('body') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        @if ($preview)
                            <div class="field">
                                <span class="field__legend">Preview</span>
                                <div class="richtext-preview">{!! $preview !!}</div>
                            </div>
                        @endif
                    </section>

                    <section class="form-section" aria-labelledby="sec-page-seo">
                        <h2 id="sec-page-seo" class="form-section__heading">Search</h2>

                        <div class="field field--inline">
                            <input type="checkbox" id="is_indexable" wire:model.live="is_indexable">
                            <label for="is_indexable">Offer this page to search engines</label>
                        </div>
                        <p class="admin-note">
                            Unticked, the page stays reachable but carries a noindex tag and is left
                            out of the sitemap. The two always agree.
                        </p>

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
                            <label for="status">Status</label>
                            <select id="status" wire:model.live="status">
                                @foreach ($statuses as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </section>

                    <div class="form-actions">
                        <button type="submit" class="admin-button">Save page</button>

                        @if ($current?->isPubliclyVisible())
                            <a href="{{ route('pages.show', $current) }}" class="admin-link">View on the site</a>
                        @endif

                        <button type="button" class="admin-link" wire:click="cancel">Cancel</button>
                    </div>
                </form>
            @endif
        </div>

        <aside>
            <h2 class="admin-subheading">All pages</h2>

            <ul class="category-list" role="list">
                @foreach ($pages as $page)
                    <li class="category-row" wire:key="page-{{ $page->id }}">
                        <span class="project-row__main">
                            <button type="button" class="admin-link" wire:click="edit('{{ $page->slug }}')">
                                {{ $page->title }}
                            </button>
                            <span class="project-row__meta">
                                /{{ $page->slug }}
                                @unless ($page->is_indexable) · noindex @endunless
                                @if ($page->is_locked) · linked from the footer @endif
                            </span>
                        </span>

                        <span class="category-row__actions">
                            <span class="badge badge--{{ $page->status->value }}">{{ $page->status->label() }}</span>

                            @unless ($page->is_locked)
                                <button type="button" class="admin-link"
                                        wire:click="delete('{{ $page->slug }}')"
                                        wire:confirm="Move “{{ $page->title }}” to the bin?">Bin</button>
                            @endunless
                        </span>
                    </li>
                @endforeach
            </ul>
        </aside>
    </div>
</div>
