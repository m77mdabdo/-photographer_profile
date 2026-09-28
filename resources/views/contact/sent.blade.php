@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo">
    <section class="enquiry enquiry--sent" aria-labelledby="sent-title">
        {{--
            Arrived here by redirect, so a refresh re-requests this GET and
            cannot resubmit anything. The page is noindex: it has nothing to
            offer a search result and would compete with the page that does.
        --}}
        <p class="enquiry__eyebrow">{{ Settings::get('nav.contact') }}</p>

        <h1 class="enquiry__title" id="sent-title">{{ Settings::get('contact.confirmation_heading') }}</h1>

        <p class="enquiry__standfirst">{{ Settings::get('contact.confirmation_body') }}</p>

        {{--
            WHAT THEY SENT, SHOWN BACK TO THEM.

            $inquiry is only ever the one this session just submitted — the
            controller flashes its id and nothing else can reach this. Someone
            opening /contact/sent directly sees the page without a receipt,
            which is right: there is nothing of theirs to show.

            The acknowledgement email says the same thing, but a person who
            has just pressed send is looking at this page, not at their inbox.
        --}}
        @if ($inquiry)
            <div class="receipt">
                <p class="receipt__note">
                    {{ Settings::get('contact.acknowledgement_note') }}
                    <strong>{{ $inquiry->email }}</strong>.
                </p>

                <blockquote class="receipt__message">{{ $inquiry->message }}</blockquote>

                @php
                    /*
                     * Only what they filled in. A row reading "Budget: —"
                     * would be the page telling somebody what they left out,
                     * on the one screen where the point is reassurance.
                     *
                     * The date is shown exactly as typed rather than as
                     * parsed: "the week of the 3rd" is what they said.
                     */
                    $facts = array_filter([
                        Settings::get('rail.place') => $inquiry->location,
                        'Kind of work' => $inquiry->project_type,
                        'Date' => $inquiry->shootDateForHumans(),
                        'Budget' => $inquiry->budget_range,
                    ]);
                @endphp
                @if ($facts !== [])
                    <dl class="receipt__facts">
                        @foreach ($facts as $label => $value)
                            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                @endif

                <p class="receipt__stamp">
                    Sent {{ $inquiry->created_at?->format('j F Y') }}
                    at {{ $inquiry->created_at?->format('H:i') }}
                </p>
            </div>
        @endif

        <nav class="enquiry__onward" aria-label="Where to go next">
            <a class="enquiry__onward-link" href="{{ route('work.index') }}">
                {{ Settings::get('nav.work') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
            <a class="enquiry__onward-link" href="{{ route('journal.index') }}">
                {{ Settings::get('nav.journal') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
            <a class="enquiry__onward-link" href="{{ route('archive.index') }}">
                {{ Settings::get('nav.archive') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
        </nav>
    </section>
</x-layouts::public>
