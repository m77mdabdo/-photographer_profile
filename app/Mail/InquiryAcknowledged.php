<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Inquiry;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The reply the SENDER gets, quoting their own brief back to them.
 *
 * WHY THIS IS THE BIGGEST THING MISSING
 *
 * Until now, sending an enquiry produced a page that said "it arrived" and
 * then silence. The sender had no copy of what they wrote, no record that it
 * went anywhere, and nothing in their inbox to reply to or search for later.
 * From their side that is indistinguishable from a form that failed quietly,
 * which is the exact anxiety that makes people send the same message twice or
 * decide the studio is not taking work.
 *
 * Quoting their own words back is the part that turns a receipt into the start
 * of a conversation. It proves what was received, gives them something to add
 * to, and puts the studio's address in their inbox so their reply threads.
 *
 * NOT A NO-REPLY. From the studio address in settings, with replies going to
 * the same place, because the whole point is that a person is at the other end.
 */
class InquiryAcknowledged extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Inquiry $inquiry) {}

    public function envelope(): Envelope
    {
        $studio = Settings::get('site.name', 'the studio');

        $from = Settings::get('contact.recipient');

        return new Envelope(
            /*
             * Their own words in the subject line, trimmed to something an
             * inbox will show. It makes the message findable six months later
             * by searching for what they were working on rather than for the
             * studio's name.
             */
            subject: __('studio.mail.acknowledged_subject', ['studio' => $studio]),

            // Falls back to the configured sender when no studio address is
            // set, rather than throwing inside a queued job.
            from: $from !== null ? new Address($from, (string) $studio) : null,
            replyTo: $from !== null ? [new Address($from, (string) $studio)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.inquiry-acknowledged',
            with: [
                'studio' => Settings::get('site.name', 'the studio'),
                'responseNote' => Settings::get('contact.response_note'),
                'signoff' => Settings::get('attribution.name'),
            ],
        );
    }
}
