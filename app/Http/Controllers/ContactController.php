<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Http\Requests\StoreInquiryRequest;
use App\Mail\InquiryAcknowledged;
use App\Mail\InquiryReceived;
use App\Models\Inquiry;
use App\Support\ContactAddress;
use App\Support\SeoData;
use App\Support\Settings;
use App\Support\SpamGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('contact.show', [
            // Re-issued on every render, so the timing check measures THIS
            // page view rather than whenever the form was first cached.
            'timer' => SpamGuard::issueTimer(),
            'seo' => SeoData::make(
                title: Settings::get('contact.heading', 'Contact'),
                description: Settings::get('contact.standfirst'),
            ),
        ]);
    }

    /**
     * THE ORDERING GUARANTEE
     *
     * 1. The enquiry is written and committed.
     * 2. Only then is anything handed to the mailer, and what is handed over
     *    is a queued job, so the mail is not attempted inside this request.
     * 3. That dispatch is wrapped anyway, because "queue the job" can itself
     *    fail if Redis or the database queue is unreachable.
     *
     * At no point can a mail problem reach the visitor or roll back the
     * record. The worst case is a stored enquiry that nobody was emailed
     * about — which is visible in the admin, logged here, and recoverable —
     * rather than a lost message, which is neither.
     */
    public function store(StoreInquiryRequest $request): RedirectResponse
    {
        $inquiry = new Inquiry;

        $inquiry->fill($request->inquiryAttributes());

        /*
         * Set here, never mass-assigned: the visitor supplies none of these.
         * consented_at is stamped only because `consent` was `accepted` by
         * the rules above, so the timestamp records a fact rather than a
         * checkbox's default.
         */
        $inquiry->forceFill([
            'status' => InquiryStatus::New,
            'consented_at' => now(),
            'ip_hash' => Inquiry::hashIp($request->ip()),
        ])->save();

        // Counted only now, so a rejected submission cannot be used to lock a
        // real visitor out of the form.
        SpamGuard::recordAccepted($request, $inquiry->email);

        $this->notifyStudio($inquiry);
        $this->acknowledgeSender($inquiry);

        /*
         * Post/Redirect/Get. A refresh on the confirmation page re-requests a
         * GET and cannot resubmit; the browser's "confirm resubmission"
         * dialog never appears.
         *
         * The id travels in the session rather than the URL, so the
         * confirmation can show the sender what they sent without putting a
         * guessable key to somebody's message in an address bar, a browser
         * history or a referrer header.
         */
        return redirect()
            ->route('contact.sent')
            ->with('inquiry_id', $inquiry->id);
    }

    public function sent(Request $request): View
    {
        /*
         * Only ever the enquiry this session just sent, and only once — the
         * flash is gone on the next request. Anyone arriving at /contact/sent
         * directly gets the page without the copy, which is correct: there is
         * nothing of theirs to show.
         */
        $id = $request->session()->get('inquiry_id');

        $inquiry = is_int($id) || ctype_digit((string) $id)
            ? Inquiry::query()->find((int) $id)
            : null;

        return view('contact.sent', [
            'inquiry' => $inquiry,
            'seo' => SeoData::make(
                title: Settings::get('contact.confirmation_heading', 'Sent'),
                // A thank-you page has nothing to offer a search result and
                // would compete with the page that does.
                robots: 'noindex, nofollow',
            ),
        ]);
    }

    /**
     * The studio's notification.
     */
    private function notifyStudio(Inquiry $inquiry): void
    {
        // A placeholder (example.com, .test…) counts as not set: a visitor's
        // message must never be mailed to a documentation domain.
        $recipient = ContactAddress::recipient();

        if ($recipient === null) {
            Log::warning('Inquiry stored but no real recipient is configured.', ['inquiry' => $inquiry->id]);

            return;
        }

        $this->send(
            fn () => Mail::to($recipient)->queue(new InquiryReceived($inquiry)),
            'studio notification',
            $inquiry,
        );
    }

    /**
     * The sender's copy of their own enquiry.
     */
    private function acknowledgeSender(Inquiry $inquiry): void
    {
        $this->send(
            fn () => Mail::to($inquiry->email, $inquiry->name)->queue(new InquiryAcknowledged($inquiry)),
            'sender acknowledgement',
            $inquiry,
        );
    }

    /**
     * TWO MESSAGES, INDEPENDENTLY FAILURE-SAFE.
     *
     * Each send is wrapped separately, so one failing cannot stop the other
     * being attempted — a studio address that bounces must not also cost the
     * sender their receipt, and a sender's mail server refusing must not cost
     * the studio its notification. Both are attempted after the record is
     * committed, so neither can lose the enquiry.
     *
     * Everything is swallowed and loudly logged. The visitor asked a question
     * and it is safe; showing them a failure would make them think it had not
     * arrived and either send it again or give up, both worse than a studio
     * finding an unnotified enquiry in its own admin.
     *
     * @param  callable(): mixed  $send
     */
    private function send(callable $send, string $what, Inquiry $inquiry): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::error('Inquiry '.$what.' could not be queued.', [
                'inquiry' => $inquiry->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
