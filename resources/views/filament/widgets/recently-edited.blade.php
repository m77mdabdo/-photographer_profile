<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('studio.recent.heading')"
        :description="__('studio.recent.description')"
        icon="heroicon-o-clock">

        @if ($rows->isEmpty())
            {{--
                C3 — an empty state says what will appear here and what to do
                meanwhile. "Nothing has been edited yet" alone tells a new
                owner the panel works and nothing else.
            --}}
            <p class="studio-empty">
                {{ __('studio.recent.empty') }}
                <span class="studio-empty__hint">{{ __('studio.recent.empty_hint') }}</span>
            </p>
        @else
            <ul class="studio-list" role="list">
                @foreach ($rows as $row)
                    <li class="studio-list__item">
                        <a class="studio-list__link" href="{{ $row['url'] }}">{{ $row['label'] }}</a>

                        <span class="studio-list__meta">
                            <span>{{ $row['kind'] }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $row['status'] }}</span>
                            <span aria-hidden="true">·</span>
                            <time datetime="{{ $row['at']?->toIso8601String() }}">{{ $row['at']?->diffForHumans() }}</time>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($allUrl)
            <x-slot name="footerActions">
                <a class="studio-more" href="{{ $allUrl }}">{{ __('studio.recent.open_all') }}</a>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
