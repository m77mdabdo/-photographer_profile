{{--
    The pipeline, shown only when it has something to say. See the widget for
    why an always-visible "0 failed" is worse than nothing.
--}}
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('studio.media.heading')"
        :description="__('studio.media.description')"
        icon="heroicon-o-exclamation-triangle"
        :icon-color="$failed > 0 ? 'danger' : 'warning'">

        <div class="studio-pipeline">
            @foreach ([['pending', $pending, 'gray'], ['processing', $processing, 'warning'], ['failed', $failed, 'danger']] as [$key, $count, $tone])
                <div @class(['studio-pipeline__cell', 'studio-pipeline__cell--active' => $count > 0])>
                    <span class="studio-pipeline__count" data-tone="{{ $tone }}">{{ $count }}</span>
                    <span class="studio-pipeline__label">{{ __('studio.media.'.$key) }}</span>
                </div>
            @endforeach
        </div>

        @if ($failures->isNotEmpty())
            <ul class="studio-failures" role="list">
                @foreach ($failures as $failure)
                    <li class="studio-failures__item">
                        <span class="studio-failures__name">{{ $failure->original_filename }}</span>
                        {{-- The reason, because "failed" alone cannot be acted on. --}}
                        <span class="studio-failures__reason">{{ $failure->failure_reason ?: __('studio.media.no_reason') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-slot name="footerActions">
            <x-filament::link :href="$mediaUrl">{{ __('studio.media.open_library') }}</x-filament::link>
        </x-slot>
    </x-filament::section>
</x-filament-widgets::widget>
