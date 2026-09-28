<x-mail::message>
# Thank you — it arrived

This is a copy of what you sent {{ $studio }} on {{ $inquiry->created_at?->format('j F Y') }} at {{ $inquiry->created_at?->format('H:i') }}, so you have it and know it went somewhere.

@if ($responseNote)
{{ $responseNote }}
@endif

---

**What you wrote:**

> {!! nl2br(e($inquiry->message)) !!}

@php
    /*
     * Only what they actually filled in. A table of empty rows would say
     * "you left these blank", which is not what a receipt is for.
     *
     * The date is shown as they typed it, not as it was parsed — "the week of
     * the 14th" is what they said, and echoing back "14 May" would quietly
     * put words in their mouth.
     */
    $facts = array_filter([
        'Name' => $inquiry->name,
        'Email' => $inquiry->email,
        'Phone' => $inquiry->phone,
        'Company' => $inquiry->company,
        'Kind of work' => $inquiry->project_type,
        'Date' => $inquiry->shootDateForHumans(),
        'Where' => $inquiry->location,
        'Budget' => $inquiry->budget_range,
    ]);
@endphp
@foreach ($facts as $label => $value)
- **{{ $label }}:** {{ $value }}
@endforeach

---

If any of that is wrong, or you thought of something afterwards, just reply to this message — it comes straight back to the studio and keeps everything in one thread.

@if ($signoff)
{{ $signoff }}
@else
{{ $studio }}
@endif
</x-mail::message>
