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

        <nav class="enquiry__onward" aria-label="Where to go next">
            <a class="enquiry__onward-link" href="{{ route('work.index') }}">
                {{ Settings::get('nav.work') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
            <a class="enquiry__onward-link" href="{{ route('archive.index') }}">
                {{ Settings::get('nav.archive') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
        </nav>
    </section>
</x-layouts::public>
