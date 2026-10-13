@php
    use App\Support\ExposureLine;

    $media = $this->getRecord();
    $exposure = ExposureLine::for($media);
    $body = ExposureLine::body($media);
    $missing = $this->missingRenditions();
@endphp

<x-filament-panels::page>
    <div class="studio-media-detail">

        {{-- ───────────────────────── A2 — the focal point ───────────────────────── --}}
        <section class="studio-media-detail__frame" aria-labelledby="focal-heading">
            <h2 id="focal-heading" class="studio-media-detail__heading">
                {{ __('studio.media.focal_heading') }}
            </h2>

            @if ($media->status === \App\Enums\MediaStatus::Ready && $media->variants->isNotEmpty())
                <p class="studio-media-detail__hint">{{ __('studio.media.focal_hint') }}</p>

                {{--
                    The overlay sits ON the photograph, because a focal point
                    is a point on a photograph. Three equal ways in: drag the
                    handle (pointer events, so mouse, pen and touch), click
                    anywhere on the image, or focus the handle and use the
                    arrow keys — 1% a step, 10% with Shift, Home to recentre.
                --}}
                <div class="focal"
                     data-focal
                     data-focal-x="{{ $media->focal_x }}"
                     data-focal-y="{{ $media->focal_y }}">
                    <x-picture :media="$media" sizes="(max-width: 1024px) 100vw, 640px" img-class="focal__image" />

                    <button type="button"
                            class="focal__handle"
                            data-focal-handle
                            aria-label="{{ __('studio.media.focal_handle') }}"
                            style="left: {{ $media->focal_x * 100 }}%; top: {{ $media->focal_y * 100 }}%"></button>
                </div>

                <p class="studio-media-detail__hint" data-focal-readout aria-live="polite">
                    {{ __('studio.media.focal_readout', [
                        'x' => number_format((float) $media->focal_x, 3),
                        'y' => number_format((float) $media->focal_y, 3),
                    ]) }}
                </p>

                {{--
                    A plain form, posting to the page's own Livewire method.
                    The number fields are the no-JavaScript path and stay
                    editable on their own — which is also why focal-point.js
                    dispatches an `input` event when it moves them rather than
                    only assigning .value.
                --}}
                <form wire:submit.prevent="saveFocalPoint" class="studio-media-detail__focal-form">
                    <div class="studio-media-detail__focal-fields">
                        <label for="focal_x">{{ __('studio.media.focal_x') }}</label>
                        <input type="number" id="focal_x" name="focal_x" step="0.001" min="0" max="1"
                               value="{{ $media->focal_x }}" data-focal-input-x required>

                        <label for="focal_y">{{ __('studio.media.focal_y') }}</label>
                        <input type="number" id="focal_y" name="focal_y" step="0.001" min="0" max="1"
                               value="{{ $media->focal_y }}" data-focal-input-y required>
                    </div>

                    <button type="submit" class="fi-btn fi-size-sm studio-media-detail__save">
                        {{ __('studio.media.focal_save') }}
                    </button>
                </form>
            @else
                <p class="studio-media-detail__empty">{{ __('studio.media.no_renditions') }}</p>
            @endif
        </section>

        <div class="studio-media-detail__columns">

            {{-- ───────────────────────── the file itself ─────────────────────────── --}}
            <section aria-labelledby="file-heading">
                <h2 id="file-heading" class="studio-media-detail__heading">
                    {{ __('studio.media.the_file') }}
                </h2>

                {{--
                    The filename is here and nowhere else on this screen: the
                    page is TITLED by what is in the picture, which is right
                    for reading it, and useless for the one job the filename
                    does — matching this row against a file on a card or in a
                    client's email.
                --}}
                <dl class="studio-media-detail__facts">
                    <div>
                        <dt>{{ __('studio.media.filename') }}</dt>
                        <dd class="studio-media-detail__mono" dir="ltr">{{ $media->original_filename }}</dd>
                    </div>

                    <div>
                        <dt>{{ __('studio.media.dimensions') }}</dt>
                        <dd class="studio-media-detail__mono" dir="ltr">
                            {{ $media->width }} × {{ $media->height }}
                        </dd>
                    </div>

                    <div>
                        <dt>{{ __('studio.table.status') }}</dt>
                        <dd>{{ $media->status->label() }}</dd>
                    </div>

                    @if ($media->status === \App\Enums\MediaStatus::Failed && $media->failure_reason)
                        <div>
                            <dt>{{ __('studio.attention.reason') }}</dt>
                            <dd>{{ $media->failure_reason }}</dd>
                        </div>
                    @endif

                    <div>
                        <dt>{{ __('studio.media.used_in') }}</dt>
                        <dd>{{ trans_choice('studio.media.usage_count', $media->usages()->count()) }}</dd>
                    </div>
                </dl>
            </section>

            {{-- ───────────────────────── A3 — what the camera recorded ───────────── --}}
            <section aria-labelledby="exif-heading">
                <h2 id="exif-heading" class="studio-media-detail__heading">
                    {{ __('studio.media.camera') }}
                </h2>

                {{--
                    Nothing is padded. ExposureLine returns null rather than a
                    row of dashes when the original carried no EXIF, because
                    "f/— · —s · ISO —" looks like data and is not. A scan, a
                    screenshot and an export from some editors genuinely have
                    none, and saying so is the honest answer.
                --}}
                @if ($media->hasExifData())
                    <dl class="studio-media-detail__facts">
                        @if ($body)
                            <div>
                                <dt>{{ __('studio.media.body') }}</dt>
                                <dd>{{ $body }}</dd>
                            </div>
                        @endif

                        @if ($exposure)
                            <div>
                                <dt>{{ __('studio.media.exposure') }}</dt>
                                <dd class="studio-media-detail__mono" dir="ltr">{{ $exposure->compact() }}</dd>
                            </div>
                        @endif

                        @if ($media->captured_at)
                            <div>
                                <dt>{{ __('studio.media.captured') }}</dt>
                                <dd>{{ $media->captured_at->translatedFormat('j F Y, H:i') }}</dd>
                            </div>
                        @endif
                    </dl>
                @else
                    <p class="studio-media-detail__empty">
                        {{ $media->exif_read_at
                            ? __('studio.media.no_exif')
                            : __('studio.media.exif_unread') }}
                    </p>
                @endif
            </section>

            {{-- ───────────────────────── A4 — the renditions ─────────────────────── --}}
            <section aria-labelledby="renditions-heading">
                <h2 id="renditions-heading" class="studio-media-detail__heading">
                    {{ __('studio.media.renditions') }}
                </h2>

                @if ($media->variants->isEmpty())
                    <p class="studio-media-detail__empty">{{ __('studio.media.no_renditions') }}</p>
                @else
                    {{--
                        Every rendition with its weight, because a partial
                        failure is otherwise invisible: the image reads Ready
                        because SOMETHING was written, and the page that wants
                        a 1600px webp finds none.
                    --}}
                    <table class="studio-media-detail__table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('studio.media.format') }}</th>
                                <th scope="col">{{ __('studio.media.width') }}</th>
                                <th scope="col">{{ __('studio.media.weight') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->variants() as $variant)
                                <tr>
                                    <td class="studio-media-detail__mono">{{ $variant->format }}</td>
                                    <td class="studio-media-detail__mono" dir="ltr">{{ $variant->width }}px</td>
                                    <td class="studio-media-detail__mono" dir="ltr">
                                        {{ number_format($variant->size_bytes / 1024, 1) }} kB
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($missing !== [])
                        <p class="studio-media-detail__warning">
                            {{ __('studio.media.missing_renditions', ['list' => implode(', ', $missing)]) }}
                        </p>
                    @endif
                @endif
            </section>
        </div>
    </div>

    {{--
        The focal picker's behaviour, on this page and no other — the same
        arrangement the story builder uses. A registered Filament asset would
        load it on every screen in the panel; this is the only one with a
        photograph to point at.
    --}}
    @vite('resources/js/studio/focal.js')
</x-filament-panels::page>
