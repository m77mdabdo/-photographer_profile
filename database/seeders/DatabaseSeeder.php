<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Laravel's default seeder, made safe to have in the repository.
     *
     * WHAT WAS WRONG WITH IT
     *
     * It created a user at the fixed address test@example.com with the
     * factory's password, which is the literal string "password". Nothing
     * stopped `php artisan db:seed` running on a staging or production box and
     * quietly putting a known account with a guessable credential into the
     * users table.
     *
     * Mandatory two-factor limits the damage — EnsureTwoFactorEnabled sends an
     * unenrolled user to the setup page rather than the dashboard — but it does
     * not remove it: whoever logs in first enrols their own second factor and
     * is then an Editor. "Another control happens to catch it" is not a reason
     * to ship the hole.
     *
     * So: local only, and it says so rather than failing silently. Real
     * accounts are made with `php artisan make:admin-user`, which takes its
     * password from a hidden prompt and never from a file.
     *
     * This is the same rule App\Console\Commands\DisposableAdmin follows, and
     * the audit that produced it is the one in
     * Database\Seeders\Concerns\ProtectsEditedContent.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn(
                'DatabaseSeeder only creates its test account locally. '
                .'Use `php artisan make:admin-user` to create a real Owner.'
            );

            return;
        }

        // firstOrCreate, not create: re-running must not fail on the unique
        // index, and must not reset a password somebody has since changed.
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->make(['email' => 'test@example.com'])->getAttributes(),
        );
    }
}
