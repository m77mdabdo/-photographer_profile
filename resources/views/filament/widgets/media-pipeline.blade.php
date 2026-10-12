{{--
    C4 — shown only when the pipeline has something to say, and when it does it
    names the reason and links to the broken items.

    A permanently visible "0 failed" trains people to stop reading it; a
    "3 failed" with no reason and no link tells them something is wrong and
    makes them go looking.
--}}
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('studio.media.heading')"
        :description="__('studio.media.description')"
        icon="heroicon-o-exclamation-triangle"
        :icon-color="$failed > 0 ? 'danger' : 'warning'">

        <div class="studio-pipeline">
            @foreach ([['pending', $pending, 'gray', null], ['processing', $processing, 'warning', $processingUrl], ['failed', $failed, 'danger', $failedUrl]] as [$key, $count, $tone, $href])
                @php($cell = 'studio-pipeline__cell'.($count > 0 ? ' studio-pipeline__cell--active' : ''))

                @if ($href && $count > 0)
                    <a class="{{ $cell }}" href="{{ $href }}">
                        <span class="studio-pipeline__count" data-tone="{{ $tone }}">{{ $count }}</span>
                        <span class="studio-pipeline__label">{{ __('studio.media.'.$key) }}</span>
                    </a>
                @else
                    <div class="{{ $cell }}">
                        <span class="studio-pipeline__count" data-tone="{{ $tone }}">{{ $count }}</span>
                        <span class="studio-pipeline__label">{{ __('studio.media.'.$key) }}</span>
                    </div>
                @endif
            @endforeach
        </div>

        @if ($failures->isNotEmpty())
            <ul class="studio-failures" role="list">
                @foreach ($failures as $failure)
                    <li class="studio-failures__item">
                        <span class="studio-failures__name">{{ $failure->original_filename }}</span>
                        {{-- The reason. "Failed" alone cannot be acted on. --}}
                        <span class="studio-failures__reason">{{ $failure->failure_reason ?: __('studio.media.no_reason') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($failedUrl || $mediaUrl)
            <x-slot name="footerActions">
                <a class="studio-more" href="{{ $failedUrl ?? $mediaUrl }}">
                    {{ $failed > 0 ? __('studio.media.open_failed') : __('studio.media.open_library') }}
                </a>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
