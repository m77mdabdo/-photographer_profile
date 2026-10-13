<div>
    <header class="module-header">
        <h1 class="admin-heading">Media</h1>
        <p class="admin-note">{{ $media->total() }} {{ Str::plural('image', $media->total()) }}</p>
        <a href="{{ route('legacy.media.trash') }}" class="admin-link">Trash</a>
    </header>

    {{--
        Uploader: still plain HTML posting to the controller, still driven by
        vanilla JS uploading one file at a time. Livewire's own file upload is
        deliberately not used — serial upload is what keeps a media worker
        under ~300 MB, and we are not giving that up for convenience.
    --}}
    <section class="uploader" aria-labelledby="uploader-heading">
        <h2 id="uploader-heading" class="uploader__heading">Add images</h2>

        <form method="POST"
              action="{{ route('legacy.media.store') }}"
              enctype="multipart/form-data"
              class="uploader__form"
              data-uploader
              data-endpoint="{{ route('legacy.media.store') }}">
            @csrf

            <div class="uploader__dropzone" data-dropzone>
                <label for="files" class="uploader__label">Choose images</label>
                <input type="file" id="files" name="files[]"
                       accept="{{ collect(config('media.accepted_mimes'))->implode(',') }}"
                       multiple data-file-input>
                <p class="uploader__hint">
                    Or drag them here. JPEG, PNG or WebP, up to
                    {{ (int) round(((int) config('media.max_upload_kb')) / 1024) }} MB each.
                </p>
            </div>

            <button type="submit" class="admin-button" data-fallback-submit>Upload</button>
        </form>

        <ul class="uploader__queue" role="list" data-upload-queue aria-live="polite"></ul>
    </section>

    <div class="filters" role="search">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" wire:model.live.debounce.400ms="search"
                   placeholder="Filename, alt text or credit">
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" wire:model.live="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>

        @if ($search !== '' || $status !== '')
            <button type="button" class="admin-link" wire:click="clearFilters">Clear</button>
        @endif

        <span class="filters__busy" wire:loading>Searching…</span>
    </div>

    @if ($media->isEmpty())
        <div class="empty-state">
            <h2>Nothing here yet</h2>

            @if ($search !== '' || $status !== '')
                <p>No image matches that search. Try a different term, or clear the filters.</p>
                <button type="button" class="admin-button" wire:click="clearFilters">Clear filters</button>
            @else
                <p>
                    Upload a photograph using the box above. Each one is stored as an
                    untouched original, then processed in the background into AVIF, WebP
                    and JPEG at six widths. Processing takes a few seconds per image and
                    runs on the <code>media</code> queue.
                </p>
                <p class="admin-note">Nothing appears on the public site until you place it in a story.</p>
            @endif
        </div>
    @else
        {{--
            The poll lives on this wrapper, not on the component. It is rendered
            only while something is still encoding, so when the last job finishes
            the wrapper disappears and the polling stops itself. There is no
            interval to clear and nothing to remember.
        --}}
        <div @if ($hasUnfinished) wire:poll.3s @endif>
            @if ($hasUnfinished)
                <p class="admin-note" role="status" aria-live="polite">
                    Some images are still being processed. This list updates itself.
                </p>
            @endif

            <ul class="media-grid" role="list">
                @foreach ($media as $item)
                    <li class="media-card" wire:key="media-{{ $item->id }}" data-media-id="{{ $item->id }}">
                        <a href="{{ route('legacy.media.show', $item) }}" class="media-card__link">
                            <span class="media-card__frame">
                                @if ($item->status === \App\Enums\MediaStatus::Ready && $item->variants->isNotEmpty())
                                    <x-picture :media="$item"
                                               sizes="(max-width: 700px) 50vw, 240px"
                                               img-class="media-card__image" />
                                @else
                                    <span class="media-card__placeholder"
                                          style="background-color: {{ $item->dominant_color ?? 'var(--bg-elevated)' }};
                                                 aspect-ratio: {{ $item->width ?? 3 }} / {{ $item->height ?? 2 }}"></span>
                                @endif
                            </span>

                            <span class="media-card__name">{{ $item->original_filename }}</span>
                        </a>

                        <p class="media-card__meta">
                            {{-- Machine-readable status. Nothing reads the label text. --}}
                            <span class="badge badge--{{ $item->status->value }}"
                                  data-status="{{ $item->status->value }}">
                                {{ $item->status->label() }}
                            </span>

                            @if ($item->usages_count > 0)
                                <span class="badge badge--used">Used {{ $item->usages_count }}×</span>
                            @endif

                            {{-- Nobody should ever wonder whether an image is real work. --}}
                            @if ($item->is_demo)
                                <span class="badge badge--demo" title="Stock photograph, removable with media:purge-demo">Demo</span>
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>

        {{ $media->links() }}
    @endif
</div>
