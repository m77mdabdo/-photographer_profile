<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Mail\InquiryAcknowledged;
use App\Mail\InquiryReceived;
use App\Models\Inquiry;
use App\Support\SpamGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use \Tests\Support\AssertsNoJavaScript;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();

        Mail::fake();

        // Each test starts from a clean limiter, or the fifth test in a class
        // inherits the fourth's rate-limit state.
        $this->refreshRateLimits();
    }

    /**
     * Clear the per-IP limiter.
     *
     * Used between iterations of a loop that submits several times from one
     * address, so the fifth case is not refused by the limit the first four
     * spent — which would look exactly like the code under test failing.
     */
    private function refreshRateLimits(): void
    {
        RateLimiter::clear('inquiry:ip:'.sha1('127.0.0.1'));
    }

    /**
     * A submission that should succeed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Frances Bell',
            'email' => 'frances@example.com',
            'phone' => '0191 555 0134',
            'company' => 'Meridian Press',
            'project_type' => 'Editorial',
            // Free text now, not a date input. See App\Support\LooseDate.
            'shoot_date_text' => now()->addMonth()->format('j F Y'),
            'location' => 'Newcastle',
            'budget_range' => '£2,000–£5,000',
            'message' => 'We are running a feature on north-east harbours in the spring issue and need a photographer for two days.',
            'consent' => '1',
            SpamGuard::HONEYPOT => '',
            // Rendered four seconds ago: old enough to be a human.
            SpamGuard::TIMER => Crypt::encryptString((string) (time() - 10)),
        ], $overrides);
    }

    // ----------------------------------------------------------------- 1

    public function test_a_valid_submission_is_stored_as_a_new_inquiry(): void
    {
        $before = now()->subSecond();

        $this->post('/contact', $this->payload())
            ->assertRedirect(route('contact.sent'));

        $inquiry = Inquiry::query()->sole();

        $this->assertSame('Frances Bell', $inquiry->name);
        $this->assertSame('frances@example.com', $inquiry->email);
        $this->assertSame('Meridian Press', $inquiry->company);
        $this->assertSame('Newcastle', $inquiry->location);
        $this->assertStringContainsString('north-east harbours', $inquiry->message);

        $this->assertSame(InquiryStatus::New, $inquiry->status);
        $this->assertSame('contact_form', $inquiry->source);

        // Consent is a moment, not a boolean, and it is recorded now.
        $this->assertNotNull($inquiry->consented_at);
        $this->assertTrue($inquiry->consented_at->greaterThanOrEqualTo($before));

        Mail::assertQueued(InquiryReceived::class);
    }

    public function test_the_email_is_normalised_and_blank_optionals_become_null(): void
    {
        $this->post('/contact', $this->payload([
            'email' => '  FRANCES@Example.COM  ',
            'phone' => '   ',
            'company' => '',
            'shoot_date_text' => '',
        ]))->assertRedirect(route('contact.sent'));

        $inquiry = Inquiry::query()->sole();

        $this->assertSame('frances@example.com', $inquiry->email);
        $this->assertNull($inquiry->phone);
        $this->assertNull($inquiry->company);
        $this->assertNull($inquiry->shoot_date);
        $this->assertNull($inquiry->shoot_date_text);
    }

    // ----------------------------------------------------------------- 2

    public function test_the_form_needs_no_javascript(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertNoExecutableJavaScript($html, '/contact');
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('wire:', $html);

        // A real form posting to a real endpoint.
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('action="'.route('contact.store').'"', $html);
        $this->assertStringContainsString('<button type="submit"', $html);
    }

    // ----------------------------------------------------------------- 3

    /**
     * The worst thing this page can do is lose a long message to a typo in
     * the email field.
     */
    public function test_a_validation_error_repopulates_every_field(): void
    {
        $payload = $this->payload(['email' => 'not-an-email']);

        $response = $this->from('/contact')->post('/contact', $payload);

        $response->assertRedirect('/contact');
        $response->assertSessionHasErrors('email');

        $html = $this->followingRedirects()
            ->from('/contact')
            ->post('/contact', $payload)
            ->assertOk()
            ->getContent();

        foreach (['name', 'phone', 'company', 'project_type', 'shoot_date_text', 'location', 'budget_range'] as $field) {
            $this->assertStringContainsString(
                'value="'.e($payload[$field]).'"',
                $html,
                "{$field} was not repopulated"
            );
        }

        // The message is a textarea, so it comes back between the tags.
        $this->assertStringContainsString(e($payload['message']), $html, 'the message was lost');

        // Even the invalid value comes back, so the visitor can see the typo.
        $this->assertStringContainsString('value="not-an-email"', $html);

        // And the consent box stays ticked, because they ticked it.
        $this->assertMatchesRegularExpression('/id="field-consent"[^>]*checked/', $html);

        $this->assertSame(0, Inquiry::query()->count());
    }

    public function test_errors_are_wired_to_their_fields_and_summarised(): void
    {
        $html = $this->followingRedirects()
            ->from('/contact')
            ->post('/contact', $this->payload(['email' => 'nope', 'message' => 'too short']))
            ->assertOk()
            ->getContent();

        // An announced summary that links to the fields.
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('href="#field-email"', $html);
        $this->assertStringContainsString('href="#field-message"', $html);

        // Each field marked invalid and pointed at its own message.
        $this->assertMatchesRegularExpression('/id="field-email"[^>]*aria-invalid="true"/s', $html);
        $this->assertStringContainsString('id="error-email"', $html);
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*error-email/', $html);
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*error-message/', $html);

        // Written by a person, not by the framework.
        $this->assertStringContainsString('does not look complete', $html);
    }

    public function test_every_field_has_a_real_label(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        foreach (['name', 'email', 'phone', 'company', 'project_type', 'shoot_date_text',
                  'location', 'budget_range', 'message', 'consent'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<label[^>]*for="field-'.$field.'"/',
                $html,
                "{$field} has no label"
            );
            $this->assertMatchesRegularExpression(
                '/id="field-'.$field.'"/',
                $html,
                "{$field} has no matching input id"
            );
        }

        // No placeholder is doing a label's job.
        $this->assertStringNotContainsString('placeholder=', $html);
    }

    // ----------------------------------------------------------------- 4, 5, 6

    /**
     * Every refusal looks the same. A bot that learns which check caught it
     * can defeat them one at a time.
     */
    public function test_the_honeypot_refuses_with_the_same_response_as_any_other_refusal(): void
    {
        $honeypot = $this->from('/contact')->post('/contact', $this->payload([
            SpamGuard::HONEYPOT => 'https://example.com/cheap-watches',
        ]));

        $tooFast = $this->from('/contact')->post('/contact', $this->payload([
            SpamGuard::TIMER => Crypt::encryptString((string) time()),
        ]));

        foreach ([$honeypot, $tooFast] as $response) {
            $response->assertRedirect('/contact');
            $response->assertSessionHasErrors('form');
        }

        $this->assertSame(0, Inquiry::query()->count(), 'a refused submission must store nothing');

        // Byte-for-byte the same message, so nothing distinguishes them.
        $this->assertSame(
            session('errors')->first('form'),
            'This could not be sent. Please reload the page and try again, or email the studio directly.'
        );

        // And the refusal names no field, so it cannot be traced to a check.
        $this->assertFalse(session('errors')->has(SpamGuard::HONEYPOT));
        $this->assertFalse(session('errors')->has(SpamGuard::TIMER));
    }

    public function test_a_submission_faster_than_a_human_is_refused(): void
    {
        // Instant — a script posting the form it just fetched.
        $this->from('/contact')
            ->post('/contact', $this->payload([SpamGuard::TIMER => Crypt::encryptString((string) time())]))
            ->assertSessionHasErrors('form');

        $this->assertSame(0, Inquiry::query()->count());

        // One second over the floor is a person.
        $this->from('/contact')
            ->post('/contact', $this->payload([
                SpamGuard::TIMER => Crypt::encryptString((string) (time() - SpamGuard::MIN_SECONDS - 1)),
            ]))
            ->assertRedirect(route('contact.sent'));

        $this->assertSame(1, Inquiry::query()->count());
    }

    public function test_a_missing_or_forged_timer_is_refused(): void
    {
        foreach (['', 'not-encrypted', Crypt::encryptString((string) (time() - 999999))] as $token) {
            $this->from('/contact')
                ->post('/contact', $this->payload([SpamGuard::TIMER => $token]))
                ->assertSessionHasErrors('form');
        }

        $this->assertSame(0, Inquiry::query()->count());
    }

    public function test_rate_limiting_triggers_per_ip(): void
    {
        // Same address, different senders each time, so only the IP limit
        // can be what stops it.
        for ($i = 0; $i < SpamGuard::PER_IP; $i++) {
            $this->post('/contact', $this->payload(['email' => "sender{$i}@example.com"]))
                ->assertRedirect(route('contact.sent'));
        }

        $this->assertSame(SpamGuard::PER_IP, Inquiry::query()->count());

        $this->from('/contact')
            ->post('/contact', $this->payload(['email' => 'one-too-many@example.com']))
            ->assertSessionHasErrors('form');

        $this->assertSame(SpamGuard::PER_IP, Inquiry::query()->count(), 'the sixth was stored anyway');
    }

    public function test_rate_limiting_triggers_per_email(): void
    {
        /*
         * Each request from a different address, so the IP limit cannot be
         * what fires — only the sender is the same.
         */
        for ($i = 0; $i < SpamGuard::PER_EMAIL; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post('/contact', $this->payload())
                ->assertRedirect(route('contact.sent'));
        }

        $this->assertSame(SpamGuard::PER_EMAIL, Inquiry::query()->count());

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->from('/contact')
            ->post('/contact', $this->payload())
            ->assertSessionHasErrors('form');

        $this->assertSame(SpamGuard::PER_EMAIL, Inquiry::query()->count());
    }

    public function test_a_refused_submission_does_not_count_toward_the_limit(): void
    {
        /*
         * Otherwise anyone could lock a real visitor out of the form by
         * posting rubbish from the same network, which turns a spam defence
         * into a denial of service.
         */
        /*
         * Eight, not twenty. The ROUTE carries throttle:contact at ten a
         * minute — a separate, blunter layer that does count every request,
         * deliberately, because hammering the endpoint is its own problem.
         * This test is about SpamGuard's own limiter, so it stays under that
         * ceiling and measures one thing.
         */
        for ($i = 0; $i < 8; $i++) {
            $this->from('/contact')->post('/contact', $this->payload([
                SpamGuard::HONEYPOT => 'spam',
            ]));
        }

        $this->post('/contact', $this->payload())->assertRedirect(route('contact.sent'));

        $this->assertSame(1, Inquiry::query()->count());
    }

    // ----------------------------------------------------------------- 7

    public function test_no_raw_ip_address_is_ever_stored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->post('/contact', $this->payload())
            ->assertRedirect(route('contact.sent'));

        $inquiry = Inquiry::query()->sole();

        // A 64-character hex digest, not an address.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $inquiry->ip_hash);
        $this->assertNotSame('203.0.113.42', $inquiry->ip_hash);

        // Nowhere in the whole row, under any column name.
        $row = (array) DB::table('inquiries')->where('id', $inquiry->id)->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                '203.0.113.42',
                (string) $value,
                "the address survived in {$column}"
            );
        }

        // And the hash is salted with the app key, so it is not a plain
        // sha256 anyone could reverse against the small IPv4 space.
        $this->assertNotSame(hash('sha256', '203.0.113.42'), $inquiry->ip_hash);
        $this->assertSame(Inquiry::hashIp('203.0.113.42'), $inquiry->ip_hash);
    }

    // ----------------------------------------------------------------- 8

    public function test_a_mail_failure_neither_loses_the_inquiry_nor_reaches_the_visitor(): void
    {
        /*
         * The mailer throws at the moment of queueing, which is the worst
         * case: it happens inside the request, after the record is written.
         */
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $response = $this->post('/contact', $this->payload());

        // The visitor sees success, because their message IS safe.
        $response->assertRedirect(route('contact.sent'));
        $response->assertSessionHasNoErrors();

        $inquiry = Inquiry::query()->sole();
        $this->assertSame(InquiryStatus::New, $inquiry->status);
        $this->assertStringContainsString('north-east harbours', $inquiry->message);

        // And nothing about the failure is shown to them.
        $html = $this->get(route('contact.sent'))->assertOk()->getContent();
        $this->assertStringNotContainsString('SMTP', $html);
        $this->assertStringNotContainsString('error', strtolower($html));
    }

    // ------------------------------------------------- TASK 24, test 2

    /**
     * BOTH MESSAGES, PROVED TO FAIL INDEPENDENTLY.
     *
     * The dangerous version of "failure-safe" is one try/catch around both
     * sends: the studio's notification throws, the catch swallows it, and the
     * sender's acknowledgement is never attempted at all. Nothing in the
     * logs, nothing on screen, and a sender who gets silence.
     *
     * So each is failed ALONE and the other is asserted to have gone anyway.
     */
    public function test_each_email_can_fail_without_costing_the_other(): void
    {
        // --- the studio's notification fails --------------------------------
        Mail::shouldReceive('to')
            ->once()
            ->with('studio@example.com')
            ->andThrow(new \RuntimeException('the studio mailbox is refusing'));

        $acknowledged = false;

        Mail::shouldReceive('to')
            ->once()
            ->with('frances@example.com', 'Frances Bell')
            ->andReturnUsing(function () use (&$acknowledged) {
                $acknowledged = true;

                return new class
                {
                    public function queue(mixed $mailable): void {}
                };
            });

        $this->post('/contact', $this->payload())
            ->assertRedirect(route('contact.sent'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($acknowledged, 'the studio failing stopped the sender being acknowledged');
        $this->assertSame(1, Inquiry::query()->count(), 'the enquiry was lost');
    }

    public function test_the_sender_failing_does_not_cost_the_studio_its_notification(): void
    {
        $notified = false;

        Mail::shouldReceive('to')
            ->once()
            ->with('studio@example.com')
            ->andReturnUsing(function () use (&$notified) {
                $notified = true;

                return new class
                {
                    public function queue(mixed $mailable): void {}
                };
            });

        Mail::shouldReceive('to')
            ->once()
            ->with('frances@example.com', 'Frances Bell')
            ->andThrow(new \RuntimeException('the sender domain is refusing'));

        $this->post('/contact', $this->payload())
            ->assertRedirect(route('contact.sent'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($notified, 'the sender failing stopped the studio being notified');
        $this->assertSame(1, Inquiry::query()->count(), 'the enquiry was lost');
    }

    // ------------------------------------------------- TASK 24, test 3

    public function test_the_acknowledgement_contains_the_senders_own_message(): void
    {
        $message = 'We are running a feature on north-east harbours in the spring issue and need a photographer for two days.';

        $this->post('/contact', $this->payload(['message' => $message]))
            ->assertRedirect(route('contact.sent'));

        Mail::assertQueued(InquiryAcknowledged::class, function (InquiryAcknowledged $mail) use ($message): bool {
            // Addressed to the person who wrote it, not to the studio.
            if (! $mail->hasTo('frances@example.com')) {
                return false;
            }

            $rendered = $mail->render();

            return str_contains($rendered, 'north-east harbours')
                && str_contains($rendered, 'Frances Bell');
        });

        // And the rendered body genuinely quotes them back, with the date
        // they typed rather than the date it was parsed into.
        $inquiry = Inquiry::query()->sole();
        $rendered = (new InquiryAcknowledged($inquiry))->render();

        $this->assertStringContainsString($message, html_entity_decode(strip_tags($rendered)));
        $this->assertStringContainsString($inquiry->shoot_date_text, strip_tags($rendered));
    }

    /**
     * And the page itself shows it, because somebody who has just pressed
     * send is looking at the screen, not at their inbox.
     */
    public function test_the_confirmation_page_shows_what_was_sent(): void
    {
        $response = $this->followingRedirects()->post('/contact', $this->payload());

        $html = $response->assertOk()->getContent();

        $this->assertStringContainsString('north-east harbours', $html);
        $this->assertStringContainsString('frances@example.com', $html);

        // Arriving at /contact/sent cold shows the page with no receipt —
        // there is nothing of theirs to show.
        $cold = $this->get(route('contact.sent'))->assertOk()->getContent();

        $this->assertStringNotContainsString('north-east harbours', $cold);
        $this->assertStringNotContainsString('receipt__message', $cold);
    }

    /**
     * NEITHER EMAIL CARRIES THE FRAMEWORK'S BRANDING.
     *
     * Laravel's mail layout takes its header, its title and its footer from
     * config('app.name'), and renders the LARAVEL LOGO whenever that value is
     * literally "Laravel". The acknowledgement is the studio's first reply to
     * a prospective client, and it was going out with a Laravel logo on it.
     */
    public function test_neither_email_is_branded_as_the_framework(): void
    {
        config(['app.name' => 'Laravel']);   // the default, and the trap

        $inquiry = Inquiry::query()->create($this->payload());
        $inquiry->forceFill(['status' => InquiryStatus::New, 'consented_at' => now()])->save();

        foreach ([new InquiryAcknowledged($inquiry), new InquiryReceived($inquiry)] as $mail) {
            $html = $mail->render();

            $this->assertStringNotContainsString('laravel.com/img', $html, 'the Laravel logo is in the email');
            $this->assertStringNotContainsString('<title>Laravel</title>', $html);
            $this->assertStringNotContainsString('© '.date('Y').' Laravel', $html);

            // The studio's own name, from the settings table.
            $this->assertStringContainsString((string) \App\Support\Settings::get('site.name'), $html);
        }
    }

    // ------------------------------------------------- TASK 24, test 4

    /**
     * THE REORDERING DID NOT COST ANY ACCESSIBILITY GUARANTEE.
     *
     * Re-asserted rather than assumed. Moving the message to the top and
     * wrapping the rest in fieldsets rewrote every line of that template, and
     * "the tests still pass" is only meaningful if the tests actually look at
     * the things that were moved.
     */
    public function test_the_reordered_form_keeps_every_accessibility_guarantee(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        // --- 1. the message really is first ---------------------------------
        $messagePosition = strpos($html, 'id="field-message"');
        $namePosition = strpos($html, 'id="field-name"');

        $this->assertIsInt($messagePosition);
        $this->assertIsInt($namePosition);
        $this->assertLessThan(
            $namePosition,
            $messagePosition,
            'the brief is no longer the first thing on the form'
        );

        /*
         * --- 2. the groups are real, and named ------------------------------
         *
         * Counted by the form's own class, not by tag: the footer's theme
         * switch is a fieldset too, and counting <fieldset> made this test
         * assert something about the layout instead of about the form.
         */
        $this->assertSame(2, substr_count($html, '<fieldset class="enquiry__group">'), 'the groups are not fieldsets');
        $this->assertSame(2, substr_count($html, '<legend class="enquiry__legend">'), 'a group has no legend');

        // --- 3. every control still has a label that points at it -----------
        foreach (['message', 'name', 'email', 'phone', 'company',
                  'project_type', 'shoot_date_text', 'location', 'budget_range', 'consent'] as $field) {
            $this->assertMatchesRegularExpression('/<label[^>]*for="field-'.$field.'"/', $html, "{$field} lost its label");
            $this->assertMatchesRegularExpression('/id="field-'.$field.'"/', $html, "{$field} lost its id");
        }

        // --- 4. hints are still bound, not just printed ---------------------
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*hint-message/', $html);
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*hint-shoot_date_text/', $html);

        // --- 5. consent is still unticked -----------------------------------
        $consent = substr($html, (int) strpos($html, 'id="field-consent"'), 400);
        $this->assertStringNotContainsString('checked', $consent);

        // --- 6. and the honeypot is still out of everyone's way -------------
        $this->assertStringContainsString('class="visually-gone" aria-hidden="true"', $html);
    }

    public function test_the_reordered_form_still_summarises_and_repopulates_errors(): void
    {
        $payload = $this->payload([
            'email' => 'not-an-email',
            'message' => 'short',
            'project_type' => 'Editorial portrait',
        ]);

        $html = $this->from('/contact')
            ->followingRedirects()
            ->post('/contact', $payload)
            ->assertOk()
            ->getContent();

        // The summary is announced and links to the fields.
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('href="#field-email"', $html);
        $this->assertStringContainsString('href="#field-message"', $html);

        // Errors are bound to their inputs.
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*error-email/', $html);
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*error-message/', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);

        // Nothing typed was lost — including from inside a fieldset.
        $this->assertStringContainsString('value="Editorial portrait"', $html);
        $this->assertStringContainsString('value="Frances Bell"', $html);
        $this->assertStringContainsString('short</textarea>', $html);
    }

    // ------------------------------------------------- TASK 24, test 5

    /**
     * A FREE-TEXT DATE IS NEVER A REASON TO REFUSE AN ENQUIRY.
     *
     * Parseable or not, the submission goes through and the raw string is
     * kept. The parsed column is a convenience; the text is the record.
     */
    public function test_a_free_text_date_is_stored_parseable_or_raw(): void
    {
        $cases = [
            // typed                     parsed?     kept as
            ['14 May 2027',              '2027-05-14'],
            ['2027-05-14',               '2027-05-14'],
            ['late April',               null],
            ['the week of the 3rd',      null],
            ['flexible — you tell me',   null],
            ['05/06/2027',               null],   // ambiguous: never guessed
            ['14/05/2027',               '2027-05-14'],
        ];

        foreach ($cases as $index => [$typed, $expected]) {
            Inquiry::query()->forceDelete();
            $this->refreshRateLimits();

            $this->post('/contact', $this->payload([
                'email' => "sender{$index}@example.com",
                'shoot_date_text' => $typed,
            ]))->assertRedirect(route('contact.sent'), "“{$typed}” was refused");

            $inquiry = Inquiry::query()->sole();

            // The raw string is ALWAYS kept, whatever happened to the parse.
            $this->assertSame($typed, $inquiry->shoot_date_text, "“{$typed}” was not kept verbatim");

            $this->assertSame(
                $expected,
                $inquiry->shoot_date?->toDateString(),
                "“{$typed}” parsed to the wrong thing"
            );

            // And what the studio reads is what the sender wrote.
            $this->assertSame($typed, $inquiry->shootDateForHumans());
        }
    }

    public function test_a_date_that_cannot_be_read_does_not_lose_the_submission(): void
    {
        $this->post('/contact', $this->payload([
            'shoot_date_text' => 'whenever the tide is right and the light comes back',
        ]))->assertRedirect(route('contact.sent'));

        $inquiry = Inquiry::query()->sole();

        $this->assertNull($inquiry->shoot_date);
        $this->assertSame('whenever the tide is right and the light comes back', $inquiry->shoot_date_text);
        $this->assertStringContainsString('north-east harbours', $inquiry->message);
    }

    /** heard_from is the studio's field now, and the public form cannot set it. */
    public function test_the_public_form_no_longer_asks_how_you_heard(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringNotContainsString('heard_from', $html);
        $this->assertStringNotContainsString('How you heard', $html);

        // And a submitted value for it is ignored rather than stored.
        $this->post('/contact', $this->payload(['heard_from' => 'Injected by hand']))
            ->assertRedirect(route('contact.sent'));

        $this->assertNull(Inquiry::query()->sole()->heard_from);
    }

    // ----------------------------------------------------------------- 12

    public function test_the_confirmation_is_noindex_and_a_refresh_cannot_resubmit(): void
    {
        $this->post('/contact', $this->payload())->assertRedirect(route('contact.sent'));

        $html = $this->get(route('contact.sent'))->assertOk()->getContent();

        $this->assertStringContainsString('noindex, nofollow', $html);

        // A refresh is a GET of this page. Re-requesting it as many times as
        // you like stores nothing more.
        $this->get(route('contact.sent'))->assertOk();
        $this->get(route('contact.sent'))->assertOk();

        $this->assertSame(1, Inquiry::query()->count());
    }

    // ----------------------------------------------------------------- 14

    public function test_the_query_counts_are_flat(): void
    {
        $queries = [];

        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        /*
         * WARMED FIRST, then measured.
         *
         * Three site-wide maps are read once per process and cached forever:
         * the settings map, the redirect map and the footer's legal links.
         * The earlier version of this test flushed settings before each page
         * so the order would not matter — but the other two maps arrived
         * later and could not be flushed the same way, which put the whole
         * one-time cost on whichever page happened to be measured first.
         *
         * Warming both pages first removes that entirely: what is measured is
         * the steady state, which is what every visitor after the very first
         * actually pays.
         */
        $this->get('/contact');
        $this->get('/about');

        $queries = [];
        $this->get('/contact')->assertOk();
        $contact = count($queries);

        $queries = [];
        $this->get('/about')->assertOk();
        $about = count($queries);

        /*
         * /contact costs NOTHING. Every string on it comes from the cached
         * settings map, and it touches no project, media row or inquiry —
         * which is why the form stays this cheap under the load a spam run
         * would put on it. /about pays for its client list.
         */
        $this->assertSame(
            ['contact' => 0, 'about' => 1],
            ['contact' => $contact, 'about' => $about],
            'query counts moved: '.json_encode(['contact' => $contact, 'about' => $about])
        );
    }
}
