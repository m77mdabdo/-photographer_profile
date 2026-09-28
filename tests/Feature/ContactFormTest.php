<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InquiryStatus;
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
            'shoot_date' => now()->addMonth()->format('Y-m-d'),
            'location' => 'Newcastle',
            'budget_range' => '£2,000–£5,000',
            'heard_from' => 'A previous client',
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
            'shoot_date' => '',
        ]))->assertRedirect(route('contact.sent'));

        $inquiry = Inquiry::query()->sole();

        $this->assertSame('frances@example.com', $inquiry->email);
        $this->assertNull($inquiry->phone);
        $this->assertNull($inquiry->company);
        $this->assertNull($inquiry->shoot_date);
    }

    // ----------------------------------------------------------------- 2

    public function test_the_form_needs_no_javascript(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script', $html);
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

        foreach (['name', 'phone', 'company', 'project_type', 'location', 'budget_range', 'heard_from'] as $field) {
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

        foreach (['name', 'email', 'phone', 'company', 'project_type', 'shoot_date',
                  'location', 'budget_range', 'heard_from', 'message', 'consent'] as $field) {
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
         * The settings cache is flushed before EACH page, or the second one
         * measured inherits the first one's warm map and the numbers depend
         * on the order the assertions happen to be written in.
         */
        \App\Support\Settings::flush();
        $queries = [];
        $this->get('/contact')->assertOk();
        $contact = count($queries);

        \App\Support\Settings::flush();
        $queries = [];
        $this->get('/about')->assertOk();
        $about = count($queries);

        /*
         * /contact is one query: the settings map, which carries every string
         * on the page. /about costs that plus its client list.
         *
         * Neither touches a project, a media row or an inquiry, which is why
         * the form stays this cheap under the load a spam run would put on it.
         */
        $this->assertSame(
            ['contact' => 1, 'about' => 2],
            ['contact' => $contact, 'about' => $about],
            'query counts moved: '.json_encode(['contact' => $contact, 'about' => $about])
        );
    }
}
