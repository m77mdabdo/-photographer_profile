<x-layouts::admin title="Media">
    <header class="module-header">
        <h1 class="admin-heading">Media</h1>
        <p class="admin-note">{{ $media->total() }} {{ Str::plural('image', $media->total()) }}</p>
    </header>

    {{-- Uploader. Works as a plain file input with no JavaScript at all. --}}
    <section class="uploader" aria-labelledby="uploader-heading">
        <h2 id="uploader-heading" class="uploader__heading">Add images</h2>

        <form method="POST"
              action="{{ route('admin.media.store') }}"
              enctype="multipart/form-data"
              class="uploader__form"
              data-uploader
              data-endpoint="{{ route('admin.media.store') }}">
            @csrf

            <div class="uploader__dropzone" data-dropzone>
                <label for="files" class="uploader__label">Choose images</label>
                <input type="file"
                       id="files"
                       name="files[]"
                       accept="{{ collect(config('media.accepted_mimes'))->implode(',') }}"
                       multiple
                       data-file-input>
                <p class="uploader__hint">
                    Or drag them here. JPEG, PNG or WebP, up to
                    {{ (int) round(((int) config('media.max_upload_kb')) / 1024) }} MB each.
                </p>
            </div>

            <button type="submit" class="admin-button" data-fallback-submit>Upload</button>
        </form>

        {{-- Progress is announced as it changes, for people not watching the bar. --}}
        <ul class="uploader__queue" role="list" data-upload-queue aria-live="polite"></ul>
    </section>

    <form method="GET" action="{{ route('admin.media.index') }}" class="filters" role="search">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="{{ $search }}"
                   placeholder="Filename, alt text or credit">
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>
                        {{ $case->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="admin-button">Apply</button>

        @if ($search !== '' || $status !== '')
            <a href="{{ route('admin.media.index') }}" class="admin-link">Clear</a>
        @endif
    </form>

    @if ($media->isEmpty())
        <div class="empty-state">
            <h2>Nothing here yet</h2>

            @if ($search !== '' || $status !== '')
                <p>No image matches that search. Try a different term, or clear the filters.</p>
                <a href="{{ route('admin.media.index') }}" class="admin-button">Clear filters</a>
            @else
                <p>
                    Upload a photograph using the box above. Each one is stored as an
                    untouched original, then processed in the background into AVIF, WebP
                    and JPEG at six widths. Processing takes a few seconds per image and
                    runs on the <code>media</code> queue.
                </p>
                <p class="admin-note">
                    Nothing appears on the public site until you place it in a story.
                </p>
            @endif
        </div>
    @else
        <ul class="media-grid" role="list" data-media-grid
            data-status-endpoint="{{ route('admin.media.statuses') }}">
            @foreach ($media as $item)
                <li class="media-card" data-media-id="{{ $item->id }}">
                    <a href="{{ route('admin.media.show', $item) }}" class="media-card__link">
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
                        <span class="badge badge--{{ $item->status->value }}" data-status-badge>
                            {{ $item->status->label() }}
                        </span>

                        @if ($item->usages_count > 0)
                            <span class="badge badge--used">Used {{ $item->usages_count }}×</span>
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>

        {{ $media->links() }}
    @endif
</x-layouts::admin>
