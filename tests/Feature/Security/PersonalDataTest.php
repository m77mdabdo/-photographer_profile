<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\SpamGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 E — THE PERSONAL DATA THIS APPLICATION HOLDS, AND FOR HOW LONG.
 *
 * Read off the live schema rather than from a document: the columns that
 * hold something about a person are
 *
 *   users        name, email, password, two_factor_secret, remember_token,
 *                last_login_at
 *   inquiries    name, email, phone, message, ip_hash, consented_at
 *   inquiry_notes  body, user_id
 *   activity_logs  user_id, actor_name, subject_label
 *   sessions     user_id, ip_address, user_agent
 *   password_reset_tokens  email, token
 *
 * Everything asserted below is a position this project has taken and written
 * down somewhere, turned into a test so that taking it back is a failure:
 *
 *   1. NO RAW IP IS EVER STORED by application code. Enquiries keep an HMAC
 *      keyed with APP_KEY; the activity log keeps an email and nothing else
 *      on a failed sign-in; users.last_login_ip has been dropped.
 *   2. THE AUDIT TABLE HAS A RETENTION WINDOW and it is scheduled, not left
 *      to somebody remembering.
 *   3. AN ENQUIRY RECORDS CONSENT, and the IP hash never reaches a screen.
 *   4. A DELETED USER DOES NOT TAKE THE AUDIT TRAIL WITH THEM, and does not
 *      leave a dangling foreign key either.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PersonalDataTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. NO RAW IP ──────────────────────────────────────────────────────

    public function test_an_enquiry_stores_an_hmac_and_never_the_address(): void
    {
        $ip = '203.0.113.42';

        $hash = Inquiry::hashIp($ip);

        $this->assertNotNull($hash);
        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString($ip, $hash);

        /*
         * KEYED, NOT PLAIN. An unsalted sha256 of an IPv4 address is
         * reversible by anybody willing to hash four billion strings; an HMAC
         * under APP_KEY is not. Proved by showing the digest is not the plain
         * one and that it moves with the key.
         */
        $this->assertNotSame(hash('sha256', $ip), $hash);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $this->assertNotSame($hash, Inquiry::hashIp($ip));
    }

    public function test_the_users_table_has_no_raw_ip_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('users', 'last_login_ip'),
            'users.last_login_ip is back. This application hashes IPs where it keeps them at all.'
        );
    }

    public function test_a_failed_sign_in_records_the_email_and_no_address(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $entry = ActivityLog::query()->where('event', 'sign_in_failed')->latest('id')->first();

        $this->assertNotNull($entry, 'a failed sign-in was not recorded at all');
        $this->assertSame('owner@example.test', $entry->subject_label);

        $row = (array) DB::table('activity_logs')->where('id', $entry->id)->first();

        foreach ($row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
                $value,
                "activity_logs.{$column} holds something shaped like an IP address."
            );
        }
    }

    // ── 2. THE COLUMN THE PANEL SHOWS IS NOW WRITTEN ──────────────────────

    /**
     * users.last_login_at was null for every row before this task, while
     * UserResource had a column reading it. It is filled on the Login event.
     */
    public function test_signing_in_records_when(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('a-long-enough-password'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertNull($user->last_login_at);

        $before = $user->updated_at;

        $this->actingAs($user);
        event(new Login('web', $user, false));

        $user->refresh();

        $this->assertNotNull($user->last_login_at, 'the panel column still has nothing to show');

        /*
         * AND IT IS NOT AN EDIT OF THE ACCOUNT. If a sign-in bumped
         * updated_at, every sign-in would surface on the users list as a
         * change and in the activity log as user_updated.
         */
        $this->assertEquals($before, $user->updated_at, 'a sign-in bumped updated_at');

        $this->assertSame(
            0,
            ActivityLog::query()->where('event', 'user_updated')->count(),
            'a sign-in produced a user_updated entry as well'
        );
    }

    // ── 3. RETENTION ──────────────────────────────────────────────────────

    public function test_the_audit_table_has_a_retention_window_and_it_runs(): void
    {
        /*
         * created_at IS NOT FILLABLE on ActivityLog, and passing it to
         * create() silently left all three rows dated today — which made the
         * first version of this test pass nothing and prove nothing. Forced
         * on, and the age is asserted before the prune runs.
         */
        $aged = function (string $event, int $daysAgo): ActivityLog {
            $entry = ActivityLog::query()->create(['event' => $event, 'actor_name' => 'Someone']);
            $entry->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

            return $entry->refresh();
        };

        $old = $aged('project_updated', 400);
        $recentSignIn = $aged('signed_in', 100);
        $keep = $aged('project_updated', 100);

        $this->assertSame(400, (int) $old->created_at->diffInDays(now()), 'the fixture was not actually aged');

        $this->artisan('activity:prune')->assertSuccessful();

        $this->assertNull(ActivityLog::query()->find($old->id), 'a 400-day-old entry survived');
        $this->assertNull(ActivityLog::query()->find($recentSignIn->id), 'a 100-day-old sign-in survived the 90-day window');
        $this->assertNotNull(ActivityLog::query()->find($keep->id), 'a 100-day-old edit was pruned by the 365-day window');
    }

    /**
     * A RETENTION RULE NOBODY RUNS IS NOT A RETENTION RULE. The schedule is
     * part of the guarantee, so it is asserted rather than assumed.
     */
    public function test_the_prune_is_scheduled(): void
    {
        $events = app(Schedule::class)->events();

        $commands = array_map(fn ($event): string => $event->command ?? '', $events);

        $this->assertNotEmpty(
            array_filter($commands, fn (string $c): bool => str_contains($c, 'activity:prune')),
            'activity:prune is not on the schedule; the retention window is a comment.'
        );
    }

    /**
     * Enquiries have NO automatic retention, and that is a deliberate gap
     * rather than an oversight — an enquiry is the studio's business record
     * of a commission. This test exists so the gap is a stated fact with a
     * place to argue about it rather than something discovered later.
     */
    public function test_enquiries_are_kept_until_deleted_by_hand(): void
    {
        $commands = array_map(
            fn ($event): string => $event->command ?? '',
            app(Schedule::class)->events()
        );

        $this->assertEmpty(
            array_filter($commands, fn (string $c): bool => str_contains($c, 'inquir')),
            'Something now prunes enquiries on a schedule. That is a change of policy: '
            .'an enquiry is a business record and the privacy notice says it is kept '
            .'until the studio deletes it. Update the notice with the window.'
        );

        // And they are soft-deletable, so a deletion by hand is recoverable.
        $this->assertTrue(method_exists(Inquiry::class, 'trashed'));
    }

    // ── 4. CONSENT, AND WHAT A SCREEN SHOWS ───────────────────────────────

    public function test_an_enquiry_records_consent_and_the_hash_stays_off_the_screen(): void
    {
        $this->assertTrue(Schema::hasColumn('inquiries', 'consented_at'));

        /*
         * THE HONEYPOT AND THE TIMER ARE PART OF THE FORM. Without them
         * SpamGuard refuses the submission and redirects, which is the guard
         * working — the first version of this test read that 302 as "the
         * contact form stored nothing".
         */
        $response = $this->post('/contact', [
            'name' => 'A Visitor',
            'email' => 'visitor@example.test',
            'message' => 'I would like to discuss a commission for the spring issue.',
            'consent' => '1',
            SpamGuard::HONEYPOT => '',
            SpamGuard::TIMER => Crypt::encryptString((string) (time() - 10)),
        ]);

        $inquiry = Inquiry::query()->latest('id')->first();

        $this->assertNotNull($inquiry, 'the contact form stored nothing: '.$response->status());
        $this->assertNotNull($inquiry->consented_at, 'consent was not recorded');
        $this->assertNotNull($inquiry->ip_hash);

        // The hash is not rendered back to the visitor.
        $this->assertStringNotContainsString($inquiry->ip_hash, (string) $this->get('/contact')->getContent());
    }

    // ── 5. DELETING A PERSON ──────────────────────────────────────────────

    public function test_deleting_a_user_leaves_the_audit_trail_readable(): void
    {
        $user = User::factory()->create(['name' => 'Departing Editor', 'role' => 'editor']);

        ActivityLog::query()->create([
            'event' => 'project_updated',
            'user_id' => $user->id,
            'actor_name' => $user->name,
        ]);

        $trail = ActivityLog::query()->latest('id')->first();

        $user->delete();

        /*
         * BY ID, NOT latest(). Deleting a user writes its own entry, so
         * latest() returns the deletion — whose actor is whoever did the
         * deleting, nobody in a test — and the first version of this test
         * read that as the trail having lost the name.
         */
        $entry = ActivityLog::query()->find($trail->id);

        /*
         * actor_name is DENORMALISED on purpose: the trail has to still say
         * who did it after the account is gone. Whether user_id survives
         * depends on the foreign key, and either answer is fine — what is not
         * fine is the row disappearing or the name going with it.
         */
        $this->assertNotNull($entry, 'deleting a user deleted the audit trail');
        $this->assertSame('Departing Editor', $entry->actor_name);
    }
}
