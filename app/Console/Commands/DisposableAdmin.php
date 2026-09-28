<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * A throwaway Owner for the browser harness, and nothing else.
 *
 * WHY THIS EXISTS
 *
 * The admin area is behind mandatory two-factor authentication, so the browser
 * harness could not reach a single admin page. The way through, last time, was
 * to overwrite the real owner's password and re-enrol their second factor —
 * an irreversible change to a live account, made by a script, to take a
 * screenshot. That must never be the answer again.
 *
 * So: a separate account that exists for the length of one harness run.
 *
 * THE FOUR GUARANTEES
 *
 *   1. LOCAL ONLY. handle() refuses outside the local environment before it
 *      touches anything. There is no --force.
 *   2. NEVER TOUCHES AN EXISTING USER. The account is created at a generated
 *      address in a reserved TLD, and if anything already holds that address
 *      the command aborts rather than updating it. No branch of this class
 *      can write to a row it did not just create.
 *   3. NOTHING SENSITIVE ON STDOUT. The password and the TOTP secret go to a
 *      gitignored file and nowhere else. Terminals scroll back, CI captures
 *      stdout, and a secret printed once is a secret in a log forever.
 *   4. DISPOSABLE. --delete removes the account and the credentials file. The
 *      harness calls it in a finally block, so a crashed run still cleans up.
 */
class DisposableAdmin extends Command
{
    protected $signature = 'admin:disposable
                            {--delete : Remove the disposable account and its credentials file}';

    protected $description = 'Create or remove a throwaway admin account for the browser harness (local only)';

    /**
     * Relative to the storage disk root.
     *
     * storage/app/private is gitignored wholesale by Laravel's own
     * .gitignore, so the credentials cannot be committed by accident even if
     * somebody adds the file by hand.
     */
    public const CREDENTIALS_PATH = 'storage/app/private/harness-admin.json';

    /**
     * A reserved TLD. RFC 2606 guarantees .invalid is never resolvable, so
     * this address cannot collide with a real one and cannot receive mail
     * even if something tried to send it some.
     */
    private const EMAIL_DOMAIN = 'harness.invalid';

    public function handle(): int
    {
        /*
         * The whole safety argument rests on this line. Checked first, before
         * any argument is read, so there is no path through the command that
         * reaches the database in a non-local environment.
         */
        if (! app()->environment('local')) {
            $this->error('admin:disposable only runs when APP_ENV=local. Refusing.');

            return self::FAILURE;
        }

        return $this->option('delete') ? $this->remove() : $this->create();
    }

    private function create(): int
    {
        $path = base_path(self::CREDENTIALS_PATH);

        // A previous run that was not cleaned up. Remove it first rather than
        // leaving two disposable owners behind.
        if (is_file($path)) {
            $this->remove();
        }

        $email = 'harness-'.Str::lower(Str::random(16)).'@'.self::EMAIL_DOMAIN;

        /*
         * Guarantee 2, asserted rather than assumed. Str::random makes a
         * collision implausible; this makes it impossible for the command to
         * write to somebody else's row even so.
         */
        if (User::query()->where('email', $email)->exists()) {
            $this->error('Generated address is already taken. Refusing to touch an existing user.');

            return self::FAILURE;
        }

        $password = Str::random(32);
        $secret = (new Google2FA())->generateSecretKey();

        $user = new User();
        $user->fill([
            'name' => 'Browser Harness',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Guarded columns, set explicitly — the same way MakeAdminUser does.
        $user->role = UserRole::Owner;
        $user->email_verified_at = now();
        $user->save();

        /*
         * Pre-confirmed, so the harness lands on the dashboard rather than on
         * the enrolment page. Recovery codes are generated because Fortify's
         * challenge screen offers them and a null column there would be a
         * 500 on a page the harness is about to open.
         */
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(
                collect(range(1, 8))->map(fn (): string => Str::random(10).'-'.Str::random(10))->all()
            )),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->writeCredentials($path, [
            'id' => $user->id,
            'email' => $email,
            'password' => $password,
            'totp_secret' => $secret,
            'created_at' => now()->toIso8601String(),
        ]);

        // Guarantee 3: the path, and not one character of what is in it.
        $this->info('Disposable admin created. Credentials written to '.self::CREDENTIALS_PATH);

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $path = base_path(self::CREDENTIALS_PATH);

        $id = null;

        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            $id = is_array($data) && isset($data['id']) ? (int) $data['id'] : null;

            @unlink($path);
        }

        /*
         * Deleted by id from the file when there is one, and otherwise by the
         * address pattern — so a run that was killed before writing the file
         * still gets cleaned up. Both are scoped to the reserved domain, so
         * neither can reach a real account.
         */
        $query = User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN);

        if ($id !== null) {
            $query->where('id', $id);
        }

        $deleted = $query->delete();

        $this->info($deleted > 0
            ? 'Disposable admin removed.'
            : 'No disposable admin to remove.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function writeCredentials(string $path, array $credentials): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            (string) json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Owner-only. The file holds a password and a TOTP seed; on a shared
        // machine the default umask would leave it world-readable.
        chmod($path, 0600);
    }
}
