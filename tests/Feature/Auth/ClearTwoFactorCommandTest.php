<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * admin:clear-two-factor — ONE ACCOUNT, LOCALLY, AND NOTHING ELSE.
 *
 * Switching enforcement off stops the panel REQUIRING a second factor; it
 * does not stop Fortify ASKING for one, because Fortify challenges whenever
 * the account has a two_factor_confirmed_at. The client's own account enrolled
 * before the switch existed, so without this command they would still meet
 * the code screen at login with a password in their hand.
 *
 * It removes a credential, so the tests are about what it REFUSES as much as
 * what it does: not outside local, not an address that does not exist, and
 * not one byte of any other row.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ClearTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE SUITE RUNS AS APP_ENV=testing, AND THE COMMAND REFUSES ANYTHING BUT
     * local — so the environment is set here rather than in each test.
     *
     * That is not a workaround for the guard; the guard is asserted directly
     * in test_it_refuses_outside_local(), which puts the environment back to
     * production, staging and testing in turn and watches the command refuse
     * each one. Everything else in this file is about what the command does
     * when it is allowed to run.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()['env'] = 'local';
        config(['app.env' => 'local']);
    }

    private function enrolled(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::Owner]);

        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETVALUE'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    // ----------------------------------------------------------------- 1

    /** It clears the three columns of the named account, and only those. */
    public function test_it_clears_the_named_account(): void
    {
        $user = $this->enrolled('owner@example.com');

        $passwordBefore = $user->password;

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@example.com'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasTwoFactorEnabled());

        /*
         * AND NOTHING ELSE MOVED. The command neither accepts nor prints nor
         * changes a password; asserting the hash is unchanged is how that is
         * proven rather than asserted.
         */
        $this->assertSame($passwordBefore, $user->password);
        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertSame('owner@example.com', $user->email);
    }

    // ----------------------------------------------------------------- 2

    /** Every other account is untouched. */
    public function test_it_touches_only_the_named_account(): void
    {
        $target = $this->enrolled('target@example.com');
        $bystander = $this->enrolled('bystander@example.com');

        $this->artisan('admin:clear-two-factor', ['email' => 'target@example.com'])
            ->assertSuccessful();

        $this->assertNull($target->refresh()->two_factor_secret);

        $bystander->refresh();

        $this->assertNotNull($bystander->two_factor_secret, "a bystander's second factor was cleared");
        $this->assertNotNull($bystander->two_factor_recovery_codes);
        $this->assertNotNull($bystander->two_factor_confirmed_at);
        $this->assertTrue($bystander->hasTwoFactorEnabled());
    }

    /**
     * An address that does not exist is an ERROR, not a silent success.
     *
     * A typo that returned 0 would leave somebody believing a factor was
     * cleared when it was not — and they would discover it at the login
     * screen, which is the worst moment.
     */
    public function test_an_unknown_address_fails_and_changes_nothing(): void
    {
        $user = $this->enrolled('owner@example.com');

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@exmaple.com'])
            ->expectsOutputToContain('No account with the address')
            ->assertFailed();

        $this->assertNotNull($user->refresh()->two_factor_secret);
    }

    /** A partial address matches nothing. No wildcards, no "starts with". */
    public function test_the_match_is_exact(): void
    {
        $user = $this->enrolled('owner@example.com');

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@example'])->assertFailed();
        $this->artisan('admin:clear-two-factor', ['email' => '%@example.com'])->assertFailed();
        $this->artisan('admin:clear-two-factor', ['email' => 'OWNER@EXAMPLE.COM'])->assertSuccessful();

        /*
         * The last one succeeds, and that is MySQL's collation rather than a
         * wildcard: the email column is case-insensitive, so the address
         * matched exactly one row — the right one. Worth knowing, and worth
         * asserting, because it means an operator who shouts the address still
         * hits the account they meant.
         */
        $this->assertNull($user->refresh()->two_factor_secret);
    }

    // ----------------------------------------------------------------- 3

    /**
     * NOT OUTSIDE LOCAL. There is no --force.
     *
     * Checked before the argument is read, so there is no path through the
     * command that reaches the database in a non-local environment.
     */
    public function test_it_refuses_outside_local(): void
    {
        $user = $this->enrolled('owner@example.com');

        foreach (['production', 'staging', 'testing'] as $environment) {
            app()['env'] = $environment;
            config(['app.env' => $environment]);

            $this->artisan('admin:clear-two-factor', ['email' => 'owner@example.com'])
                ->expectsOutputToContain('only runs when APP_ENV=local')
                ->assertFailed();

            $this->assertNotNull(
                $user->refresh()->two_factor_secret,
                "the command ran in $environment"
            );
        }
    }

    // ----------------------------------------------------------------- 4

    /** An account with nothing enrolled is a no-op that says so. */
    public function test_an_account_with_no_factor_is_a_no_op(): void
    {
        User::factory()->create(['email' => 'plain@example.com', 'role' => UserRole::Owner])
            ->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])
            ->save();

        $this->artisan('admin:clear-two-factor', ['email' => 'plain@example.com'])
            ->expectsOutputToContain('no two-factor enrolment')
            ->assertSuccessful();
    }

    /**
     * With enforcement still ON it warns, because the result would look like
     * the command having failed: the account signs in and is then redirected
     * to the setup page on every request.
     */
    public function test_it_warns_when_enforcement_is_still_on(): void
    {
        config(['auth.require_two_factor' => true]);

        $this->enrolled('owner@example.com');

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@example.com'])
            ->expectsOutputToContain('Enforcement is still ON')
            ->assertSuccessful();
    }

    /** And stays quiet when it is off. */
    public function test_it_does_not_warn_when_enforcement_is_off(): void
    {
        config(['auth.require_two_factor' => false]);

        $this->enrolled('owner@example.com');

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@example.com'])
            ->doesntExpectOutputToContain('Enforcement is still ON')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------------- 5

    /** Nothing secret is printed, and no password is accepted. */
    public function test_it_neither_prints_nor_accepts_a_secret(): void
    {
        $user = $this->enrolled('owner@example.com');

        $secret = $user->two_factor_secret;

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@example.com'])
            ->doesntExpectOutputToContain((string) $secret)
            ->doesntExpectOutputToContain('SECRETVALUE')
            ->doesntExpectOutputToContain($user->password)
            ->assertSuccessful();

        // There is no password option to pass one to.
        $definition = (new \App\Console\Commands\ClearTwoFactor())->getDefinition();

        $this->assertFalse($definition->hasOption('password'));
        $this->assertSame(['email'], array_keys($definition->getArguments()));
    }

    // ----------------------------------------------------------------- 6

    /** The cleared account then signs in with a password alone. */
    public function test_the_cleared_account_signs_in_without_a_code(): void
    {
        config(['auth.require_two_factor' => false]);

        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => bcrypt('a-long-enough-password'),
            'role' => UserRole::Owner,
        ]);

        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETVALUE'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->artisan('admin:clear-two-factor', ['email' => 'owner@example.com'])->assertSuccessful();

        /*
         * Back to the suite's own environment for the HTTP half. setUp() set
         * APP_ENV=local so the command would run at all, and under `local`
         * Laravel's VerifyCsrfToken is live — it only steps aside for
         * `testing` — so posting the form without a token was a 419 and
         * nothing to do with two-factor at all.
         */
        app()['env'] = 'testing';
        config(['app.env' => 'testing']);

        /*
         * NOT redirected to /two-factor-challenge, which is where Fortify
         * sends an enrolled account. This is the whole point of the command.
         */
        $response = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'a-long-enough-password',
        ]);

        $response->assertRedirect();
        $this->assertStringNotContainsString(
            'two-factor-challenge',
            (string) $response->headers->get('Location'),
        );

        $this->assertAuthenticatedAs($user->fresh());
    }
}
