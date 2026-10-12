<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('studio.recent.heading')"
        :description="__('studio.recent.description')"
        icon="heroicon-o-clock">

        @if ($rows->isEmpty())
            {{-- An empty state that reads as a beginning, not a fault. --}}
            <p class="studio-empty">{{ __('studio.recent.empty') }}</p>
        @else
            <ul class="studio-list" role="list">
                @foreach ($rows as $row)
                    <li class="studio-list__item">
                        <a class="studio-list__link" href="{{ $row['url'] }}">{{ $row['label'] }}</a>

                        <span class="studio-list__meta">
                            <span class="studio-list__kind">{{ $row['kind'] }}</span>
                            <span aria-hidden="true">·</span>
                            <span class="studio-list__status">{{ $row['status'] }}</span>
                            <span aria-hidden="true">·</span>
                            <time datetime="{{ $row['at']?->toIso8601String() }}">{{ $row['at']?->diffForHumans() }}</time>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
