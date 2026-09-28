<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Http\Requests\StoreInquiryRequest;
use App\Mail\InquiryReceived;
use App\Models\Inquiry;
use App\Support\SeoData;
use App\Support\Settings;
use App\Support\SpamGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
        $inquiry = new Inquiry();

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

        $this->notify($inquiry);

        /*
         * Post/Redirect/Get. A refresh on the confirmation page re-requests a
         * GET and cannot resubmit; the browser's "confirm resubmission"
         * dialog never appears.
         */
        return redirect()->route('contact.sent');
    }

    public function sent(): View
    {
        return view('contact.sent', [
            'seo' => SeoData::make(
                title: Settings::get('contact.confirmation_heading', 'Sent'),
                // A thank-you page has nothing to offer a search result and
                // would compete with the page that does.
                robots: 'noindex, nofollow',
            ),
        ]);
    }

    private function notify(Inquiry $inquiry): void
    {
        $recipient = Settings::get('contact.recipient');

        if ($recipient === null) {
            Log::warning('Inquiry stored but no recipient is configured.', ['inquiry' => $inquiry->id]);

            return;
        }

        try {
            Mail::to($recipient)->queue(new InquiryReceived($inquiry));
        } catch (Throwable $e) {
            /*
             * Swallowed on purpose, and loudly logged. The enquiry is already
             * committed; the visitor asked a question and it is safe. Showing
             * them a failure would make them think it had not arrived and
             * either send it again or give up — both worse than a studio
             * finding an unnotified enquiry in its own admin.
             */
            Log::error('Inquiry notification could not be queued.', [
                'inquiry' => $inquiry->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
