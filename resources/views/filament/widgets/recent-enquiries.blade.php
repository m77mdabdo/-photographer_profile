<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('studio.enquiries.heading')"
        :description="__('studio.enquiries.description')"
        icon="heroicon-o-envelope">

        @if ($enquiries->isEmpty())
            <p class="studio-empty">{{ __('studio.enquiries.empty') }}</p>
        @else
            <ul class="studio-list" role="list">
                @foreach ($enquiries as $enquiry)
                    <li @class(['studio-list__item', 'studio-list__item--unread' => $enquiry->status === \App\Enums\InquiryStatus::New])>
                        <a class="studio-list__link" href="{{ route('admin.inquiries.show', $enquiry) }}">
                            {{ $enquiry->name }}
                            @if ($enquiry->status === \App\Enums\InquiryStatus::New)
                                <span class="studio-badge studio-badge--unread">{{ __('studio.enquiries.unread') }}</span>
                            @endif
                        </a>

                        {{-- The first line, so the enquiry can be triaged without opening it. --}}
                        <span class="studio-list__excerpt">{{ \Illuminate\Support\Str::limit((string) $enquiry->message, 90) }}</span>

                        <span class="studio-list__meta">
                            <time datetime="{{ $enquiry->created_at?->toIso8601String() }}">{{ $enquiry->created_at?->diffForHumans() }}</time>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-slot name="footerActions">
            <x-filament::link :href="$indexUrl">{{ __('studio.enquiries.open_all') }}</x-filament::link>
        </x-slot>
    </x-filament::section>
</x-filament-widgets::widget>
