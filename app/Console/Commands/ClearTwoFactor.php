<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\TwoFactorEnforcement;
use Illuminate\Console\Command;

/**
 * Un-enrols ONE account's second factor, locally only.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY SWITCHING ENFORCEMENT OFF IS NOT ENOUGH ON ITS OWN
 *
 * AUTH_REQUIRE_TWO_FACTOR=false stops the panel REQUIRING a second factor. It
 * does not stop Fortify ASKING for one: Fortify challenges at login whenever
 * the account it is signing in has a two_factor_confirmed_at, because that
 * column means "this person chose this and can produce codes". An account that
 * enrolled before the switch was flipped therefore still gets the code screen,
 * and the client is still locked out of their own site with a password in
 * their hand.
 *
 * So the switch answers "must they?" and this command answers "did they?".
 * Both are needed, and they are deliberately separate: one is configuration
 * and reversible by editing a file, the other is a change to a row and should
 * take a deliberate act with somebody's address typed out in full.
 *
 * THE GUARANTEES, in the order the code checks them
 *
 *   1. LOCAL ONLY. No --force, no environment argument, nothing to override.
 *      Checked before the address is even read, so there is no path to the
 *      database from a non-local environment.
 *   2. ONE NAMED ACCOUNT. The address is matched exactly. No wildcards, no
 *      --all, no "the first Owner" convenience. An address that does not
 *      exist is an error, not a no-op, because a typo that silently succeeded
 *      would leave somebody believing a factor was cleared when it was not.
 *   3. NO PASSWORDS. This command neither accepts nor prints nor changes a
 *      password. It touches three columns and says which.
 *   4. CONFIRMED OUT LOUD. It prints whose account, and what will be gone,
 *      before it does anything.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Re-enrolling is the ordinary route: sign in and visit the setup page. This
 * removes a second factor; it cannot read one, and there is nothing it could
 * print that would help an attacker who already had local shell access.
 */
class ClearTwoFactor extends Command
{
    protected $signature = 'admin:clear-two-factor
                            {email : The exact address of the ONE account to un-enrol}';

    protected $description = "Remove one account's two-factor enrolment so a password alone signs in (local only)";

    /** The three columns this command owns, and the only ones it writes. */
    private const COLUMNS = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    public function handle(): int
    {
        /*
         * Guarantee 1. First line, before the argument is read.
         */
        if (! app()->environment('local')) {
            $this->error('admin:clear-two-factor only runs when APP_ENV=local. Refusing.');
            $this->line('  A second factor is removed in production by the account holder, not by a command.');

            return self::FAILURE;
        }

        $email = trim((string) $this->argument('email'));

        if ($email === '') {
            $this->error('An address is required.');

            return self::FAILURE;
        }

        /*
         * Guarantee 2. An exact match on one row. whereKey() on the result
         * below means the write cannot widen even if this lookup ever did.
         */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the address {$email}. Nothing was changed.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret === null) {
            $this->info("{$email} has no two-factor enrolment. Nothing to do.");

            return self::SUCCESS;
        }

        $this->line("Un-enrolling <options=bold>{$email}</> ({$user->name}).");
        $this->line('  Clearing: '.implode(', ', self::COLUMNS));
        $this->line('  Leaving untouched: password, role, sessions, everything else.');

        /*
         * whereKey, so the UPDATE names one primary key rather than repeating
         * the address lookup — guarantee 2 expressed in the SQL rather than in
         * the code around it. A query builder update also bypasses $fillable,
         * which none of these three columns is in.
         */
        User::query()->whereKey($user->getKey())->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->info("Done. {$email} now signs in with an email and a password alone.");

        /*
         * And the other half of the answer, because having done this with
         * enforcement still on would leave them redirected to the setup page
         * on every request — which looks like the command failing.
         */
        if (TwoFactorEnforcement::required()) {
            $this->newLine();
            $this->warn('Enforcement is still ON (config auth.require_two_factor).');
            $this->line('  The panels will now send this account to the two-factor setup page on');
            $this->line('  every request. Set AUTH_REQUIRE_TWO_FACTOR=false in .env as well, or');
            $this->line('  re-enrol from the setup page.');
        }

        return self::SUCCESS;
    }
}
