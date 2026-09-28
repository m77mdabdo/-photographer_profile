{{-- data-dirty drives the beforeunload warning; it clears the moment save succeeds. --}}
<div data-unsaved-guard data-dirty="{{ $dirty ? '1' : '0' }}">
    <p class="breadcrumb"><a href="{{ route('admin.articles.index') }}">← All writing</a></p>

    <header class="module-header">
        <h1 class="admin-heading">{{ $article?->exists ? 'Edit piece' : 'New piece' }}</h1>
        @if ($dirty)
            <span class="badge" role="status" aria-live="polite">Unsaved changes</span>
        @endif
    </header>

    <form wire:submit="save" class="project-form">
        <section class="form-section" aria-labelledby="sec-piece">
            <h2 id="sec-piece" class="form-section__heading">The piece</h2>

            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" wire:model.live.debounce.500ms="title" maxlength="180" required>
                @error('title') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="slug">Slug</label>
                <input type="text" id="slug" wire:model.live.debounce.500ms="slug">
                <p class="field__hint">
                    The public address: <code>/journal/{{ $slug !== '' ? $slug : '…' }}</code>.
                    Generated from the title until you edit it.
                </p>
                @error('slug') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="subtitle">Standfirst</label>
                <input type="text" id="subtitle" wire:model.blur="subtitle" maxlength="255">
                <p class="field__hint">The line under the headline. One sentence, on the page itself.</p>
            </div>

            <div class="field">
                <label for="excerpt">Excerpt</label>
                <textarea id="excerpt" wire:model.blur="excerpt" rows="2" maxlength="500"></textarea>
                <p class="field__hint">
                    Shown in the index and used as the meta description. Left empty, the
                    opening of the piece is used instead.
                </p>
                @error('excerpt') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-body">
            <h2 id="sec-body" class="form-section__heading">Body</h2>

            <div class="field">
                <label for="body">The writing</label>
                <textarea id="body" wire:model.live.debounce.700ms="body" rows="20"></textarea>
                <p class="field__hint">
                    Markdown. <code>## a heading</code>, <code>*emphasis*</code>,
                    <code>&gt; a quotation</code>, <code>[a link](https://…)</code>.
                    HTML typed here is removed rather than rendered — see the preview.
                </p>
                @error('body') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            {{--
                THE PREVIEW IS THE SANITISER'S OUTPUT, not a second renderer.

                It is the same RichText::toHtml() call save() makes, so what
                is shown here is exactly what would be stored and served. That
                matters: an author who pastes markup from a web page can see
                that it did not survive, rather than discovering it on the
                published page.
            --}}
            @if ($preview)
                <div class="field">
                    <span class="field__legend">Preview</span>
                    <div class="richtext-preview">{!! $preview !!}</div>
                    <p class="admin-note">
                        Exactly what will be stored and served. Anything you typed that is
                        missing here was removed by the sanitiser.
                    </p>
                </div>
            @endif
        </section>

        <section class="form-section" aria-labelledby="sec-filing">
            <h2 id="sec-filing" class="form-section__heading">Filing</h2>

            <div class="field-row">
                <div class="field">
                    <label for="article_category_id">Subject</label>
                    <select id="article_category_id" wire:model.live="article_category_id">
                        <option value="">Unfiled</option>
                        @foreach ($categories as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <p class="field__hint">Subjects have their own landing page at <code>/journal/category/…</code>.</p>
                    @error('article_category_id') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="tagList">Tags</label>
                    <input type="text" id="tagList" wire:model.blur="tagList"
                           placeholder="large format, printing, Northumberland">
                    <p class="field__hint">Comma separated. New ones are created as you use them.</p>
                    @error('tagList') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-image">
            <h2 id="sec-image" class="form-section__heading">Image</h2>

            <div class="media-slot">
                <span class="field__legend">Featured</span>

                @if ($featuredMedia)
                    <x-picture :media="$featuredMedia" sizes="220px" img-class="media-slot__image" />
                    <p class="admin-note">{{ $featuredMedia->original_filename }}</p>
                @else
                    <p class="admin-note">No image chosen. The piece runs as text.</p>
                @endif

                <button type="button" class="admin-button admin-button--quiet"
                        wire:click="$dispatch('open-picker', { slot: 'featured' })">
                    {{ $featuredMedia ? 'Change image' : 'Choose image' }}
                </button>
                @error('featured_media_id') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-seo">
            <h2 id="sec-seo" class="form-section__heading">Search</h2>
            <p class="admin-note">
                All optional. Left empty, the title and excerpt above are used, which is
                usually the right answer.
            </p>

            <div class="field">
                <label for="seo_title">Search title</label>
                <input type="text" id="seo_title" wire:model.blur="seo_title" maxlength="70">
                <p class="field__hint">Results cut off around 70 characters.</p>
                @error('seo_title') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="seo_description">Search description</label>
                <textarea id="seo_description" wire:model.blur="seo_description" rows="2" maxlength="180"></textarea>
                @error('seo_description') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="canonical_url">Canonical URL</label>
                <input type="url" id="canonical_url" wire:model.blur="canonical_url"
                       placeholder="https://…">
                <p class="field__hint">
                    Only if this ran somewhere else first and that version is the original.
                    Empty means this page is the canonical, which is nearly always the case.
                </p>
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
                    <p class="field__hint">Leave empty and publishing fills it with the moment you save. A future time schedules it.</p>
                    @error('published_at') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="admin-button">Save piece</button>

            @if ($article?->exists && $article->isPubliclyVisible())
                <a href="{{ route('journal.show', $article) }}" class="admin-link">View on the site</a>
            @endif

            <a href="{{ route('admin.articles.index') }}" class="admin-link">Cancel</a>
        </div>
    </form>

    {{-- The same picker the project editor uses. --}}
    <livewire:admin.media-picker />
</div>
