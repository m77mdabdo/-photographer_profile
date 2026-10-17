<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The studio's notification that an enquiry arrived.
 *
 * ShouldQueue is the whole safety design: this is handed to the queue AFTER
 * the enquiry is committed, so a broken mail server delays a notification and
 * can never lose a message. See ContactController.
 */
class InquiryReceived extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Inquiry $inquiry) {}

    public function envelope(): Envelope
    {
        $name = $this->inquiry->name;

        return new Envelope(
            /*
             * 35 H4 — TRANSLATED, LIKE THE BODY BESIDE IT.
             *
             * A literal. The body of this message has been translated since
             * it was written and the line the studio actually sees in its
             * inbox was English whatever language the panel is in.
             */
            subject: __('studio.mail.received_subject', ['name' => $name]),
            /*
             * Reply-To, not From. Sending AS the enquirer would fail SPF at
             * most receivers and land the notification in spam — the one
             * place it must not go. This way the studio hits reply and the
             * message goes to the right person anyway.
             */
            replyTo: [new Address($this->inquiry->email, $name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.inquiry-received',
            with: [
                /*
                 * Absolute, because a link in an email has no page to resolve
                 * a relative path against — the same reasoning as og:image.
                 *
                 * Pointed at the PANEL since Task 34 Part B. It named the
                 * custom admin's enquiry screen, which no longer exists; the
                 * redirect at /legacy/inquiries/{id} would have caught it,
                 * but sending somebody a link that needs a redirect is a link
                 * that breaks the day the redirect is tidied away.
                 */
                'url' => \App\Filament\Resources\Enquiries\InquiryResource::getUrl(
                    'view',
                    ['record' => $this->inquiry],
                ),
            ],
        );
    }
}
