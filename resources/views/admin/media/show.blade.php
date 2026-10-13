<x-layouts::admin :title="$medium->original_filename">
    <p class="breadcrumb"><a href="{{ route('legacy.media.index') }}">← All media</a></p>

    <div class="detail">
        <div class="detail__preview">
            @if ($medium->status === \App\Enums\MediaStatus::Ready && $medium->variants->isNotEmpty())
                {{--
                    The focal picker sits on top of the image. Clicking sets the
                    point; arrow keys nudge it by 1% and Shift+arrow by 10%, so
                    it is fully usable without a pointer.
                --}}
                <div class="focal"
                     data-focal
                     data-focal-x="{{ $medium->focal_x }}"
                     data-focal-y="{{ $medium->focal_y }}">
                    <x-picture :media="$medium" sizes="(max-width: 900px) 100vw, 620px" />

                    <button type="button"
                            class="focal__handle"
                            data-focal-handle
                            aria-label="Focal point. Use the arrow keys to move it, or click the image."
                            style="left: {{ $medium->focal_x * 100 }}%; top: {{ $medium->focal_y * 100 }}%">
                    </button>
                </div>

                <p class="admin-note focal__readout" data-focal-readout aria-live="polite">
                    Focal point: {{ number_format((float) $medium->focal_x, 3) }},
                    {{ number_format((float) $medium->focal_y, 3) }}
                </p>
            @else
                <div class="detail__pending">
                    <p class="badge badge--{{ $medium->status->value }}">{{ $medium->status->label() }}</p>

                    @if ($medium->failure_reason)
                        <p class="admin-note">{{ $medium->failure_reason }}</p>
                    @else
                        <p class="admin-note">No renditions yet. Run the media queue to process this image.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="detail__side">
            <h1 class="admin-heading detail__title">{{ $medium->original_filename }}</h1>

            @if ($medium->is_demo)
                <p class="demo-notice" role="note">
                    <span class="badge badge--demo">Demo</span>
                    Stock photograph, not your own work.
                    @if ($medium->credit) By {{ $medium->credit }}. @endif
                    Remove every demo image with <code>php artisan media:purge-demo</code>.
                </p>
            @endif

            <dl class="facts">
                <dt>Status</dt>
                <dd><span class="badge badge--{{ $medium->status->value }}">{{ $medium->status->label() }}</span></dd>

                <dt>Dimensions</dt>
                <dd>{{ $medium->width }} × {{ $medium->height }}</dd>

                <dt>Original size</dt>
                <dd>{{ number_format($medium->size_bytes / 1048576, 2) }} MB</dd>

                <dt>Type</dt>
                <dd>{{ $medium->mime_type }}</dd>

                <dt>Uploaded</dt>
                <dd>{{ $medium->created_at?->format('j M Y, H:i') }}{{ $medium->uploader ? ' by '.$medium->uploader->name : '' }}</dd>

                <dt>Renditions</dt>
                <dd>{{ $medium->variants->count() }}</dd>

                <dt>Used in</dt>
                <dd>{{ $medium->usages->count() }} {{ Str::plural('place', $medium->usages->count()) }}</dd>
            </dl>

            {{--
                Camera data, read from the original when it was processed.

                Only what the frame actually carries is shown. A file with no
                camera tags — which is every stock download, because agencies
                strip them — gets a plain statement to that effect rather than
                a table of dashes, so "we know nothing" is never mistaken for
                "this was shot at f/—".

                There is no location row, and there is no column behind one.
            --}}
            <h2 class="admin-subheading">Camera</h2>

            @if ($medium->hasExifData())
                <dl class="facts">
                    @if ($exposure = $medium->exposureLine())
                        <dt>Exposure</dt>
                        <dd class="facts__technical">{{ $exposure->compact() }}</dd>
                    @endif

                    @if ($medium->exif_camera)
                        <dt>Body</dt>
                        <dd>{{ $medium->exif_camera }}</dd>
                    @endif

                    @if ($medium->exif_lens)
                        <dt>Lens</dt>
                        <dd>{{ $medium->exif_lens }}</dd>
                    @endif

                    @if ($medium->captured_at)
                        <dt>Captured</dt>
                        <dd>{{ $medium->captured_at->format('j M Y, H:i') }}</dd>
                    @endif
                </dl>
            @else
                <p class="admin-note">
                    @if ($medium->exif_read_at)
                        No camera data in this file. Nothing is shown rather than a placeholder.
                    @else
                        The original has not been read yet. Run <code>media:backfill-exif</code>.
                    @endif
                </p>
            @endif

            <form method="POST" action="{{ route('legacy.media.update', $medium) }}" class="admin-form">
                @csrf
                @method('PATCH')

                <x-auth-errors />

                <div class="field">
                    <label for="alt">Alt text</label>
                    <input type="text" id="alt" name="alt" value="{{ old('alt', $medium->alt) }}" maxlength="255">
                    <p class="field__hint">What the image shows, for anyone who cannot see it. Leave empty if purely decorative.</p>
                </div>

                <div class="field">
                    <label for="caption">Caption</label>
                    <textarea id="caption" name="caption" rows="3" maxlength="2000">{{ old('caption', $medium->caption) }}</textarea>
                </div>

                <div class="field">
                    <label for="credit">Credit</label>
                    <input type="text" id="credit" name="credit" value="{{ old('credit', $medium->credit) }}" maxlength="255">
                </div>

                <div class="field">
                    <label for="copyright">Copyright</label>
                    <input type="text" id="copyright" name="copyright" value="{{ old('copyright', $medium->copyright) }}" maxlength="255">
                </div>

                {{-- Kept in sync by the picker, and directly editable without it. --}}
                <div class="field field--pair">
                    <span class="field__legend">Focal point</span>

                    <label for="focal_x">X</label>
                    <input type="number" id="focal_x" name="focal_x" step="0.001" min="0" max="1"
                           value="{{ old('focal_x', $medium->focal_x) }}" data-focal-input-x required>

                    <label for="focal_y">Y</label>
                    <input type="number" id="focal_y" name="focal_y" step="0.001" min="0" max="1"
                           value="{{ old('focal_y', $medium->focal_y) }}" data-focal-input-y required>
                </div>

                <button type="submit" class="admin-button">Save details</button>
            </form>

            <div class="detail__actions">
                <form method="POST" action="{{ route('legacy.media.reprocess', $medium) }}">
                    @csrf
                    <button type="submit" class="admin-button admin-button--quiet">Reprocess renditions</button>
                </form>

                {{--
                    Deleting asks first. The dialog is a <details> so the
                    confirmation exists in the markup rather than depending on
                    window.confirm, and it is reachable by keyboard.
                --}}
                <details class="danger">
                    <summary>Delete this image</summary>

                    <div class="danger__body">
                        @if ($medium->usages->count() > 0)
                            <p class="admin-note">
                                This image is used in {{ $medium->usages->count() }}
                                {{ Str::plural('place', $medium->usages->count()) }}. Remove it there
                                before deleting.
                            </p>
                        @else
                            <p class="admin-note">
                                This moves the image to trash. The original and its renditions
                                stay on disk and can be restored.
                            </p>

                            <form method="POST" action="{{ route('legacy.media.destroy', $medium) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-button admin-button--danger">
                                    Yes, move to trash
                                </button>
                            </form>
                        @endif
                    </div>
                </details>
            </div>

            @if ($medium->variants->isNotEmpty())
                <details class="variants">
                    <summary>{{ $medium->variants->count() }} renditions</summary>

                    <table class="variants__table">
                        <thead>
                            <tr><th scope="col">Format</th><th scope="col">Width</th><th scope="col">Size</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($medium->variants->sortBy([['format', 'asc'], ['width', 'asc']]) as $variant)
                                <tr>
                                    <td>{{ $variant->format }}</td>
                                    <td>{{ $variant->width }}px</td>
                                    <td>{{ number_format($variant->size_bytes / 1024, 1) }} KB</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
        </div>
    </div>
</x-layouts::admin>
