<x-mail::message>
# Enquiry from {{ $inquiry->name }}

{{-- One line, with real separators. These ran together into
     "frances@example.testMeridian Press" because a Markdown line break needs
     two spaces and a bare @if on its own line supplies neither. --}}
{{ $inquiry->email }}@if ($inquiry->phone) · {{ $inquiry->phone }}@endif @if ($inquiry->company) · {{ $inquiry->company }}@endif

**{{ $inquiry->message }}**

@php
    $facts = array_filter([
        'Kind of work' => $inquiry->project_type,
        'Date' => $inquiry->shootDateForHumans(),
        'Location' => $inquiry->location,
        'Budget' => $inquiry->budget_range,
        'Heard via' => $inquiry->heard_from,
    ]);
@endphp
@if ($facts !== [])
@foreach ($facts as $label => $value)
- **{{ $label }}:** {{ $value }}
@endforeach
@endif

<x-mail::button :url="$url">
Open in the admin
</x-mail::button>

Arrived {{ $inquiry->created_at?->format('j F Y, H:i') }}.
Consent recorded {{ $inquiry->consented_at?->format('j F Y, H:i') }}.

</x-mail::message>
