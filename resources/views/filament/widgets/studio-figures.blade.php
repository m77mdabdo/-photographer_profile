<x-filament-widgets::widget>
    {{--
        ONE BAND, FOUR CLUSTERS.

        Not <x-filament::section>: a section adds a heading row and padding
        this band does not want. It is a row of facts, and the only chrome it
        needs is the hairline that separates one cluster from the next.

        At 390 the clusters stack and the figures inside each stay side by
        side, so a phone reads four short rows rather than eight tall ones.
        Nothing is hidden at any width — see the Task 33 report on tables.
    --}}
    <div class="studio-figures" role="group" aria-label="{{ __('studio.figures.label') }}">
        @foreach ($this->getClusters() as $cluster)
            <div class="studio-figures__cluster">
                <p class="studio-figures__group">{{ $cluster['group'] }}</p>

                <div class="studio-figures__row">
                    @foreach ($cluster['figures'] as $figure)
                        @php
                            $tag = $figure['url'] ? 'a' : 'div';
                        @endphp

                        <{{ $tag }}
                            @class([
                                'studio-figure',
                                'studio-figure--urgent' => $figure['tone'] === 'urgent',
                                'studio-figure--link' => (bool) $figure['url'],
                            ])
                            @if ($figure['url']) href="{{ $figure['url'] }}" @endif
                        >
                            {{-- Western numerals in both locales: ADR 0005. --}}
                            <span class="studio-figure__value">{{ $figure['value'] }}</span>
                            <span class="studio-figure__label">{{ $figure['label'] }}</span>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
