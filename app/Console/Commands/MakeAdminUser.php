<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first (or another) Owner.
 *
 * The password is only ever read from a hidden prompt. It is deliberately not
 * accepted as an argument or an option: anything on the command line lands in
 * the shell history, in `ps` output and in CI logs.
 */
class MakeAdminUser extends Command
{
    protected $signature = 'make:admin-user
                            {--name= : The user\'s full name}
                            {--email= : The user\'s email address}';

    protected $description = 'Create an Owner account interactively';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $email = (string) ($this->option('email') ?: $this->ask('Email address'));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user with the email {$email} already exists.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Confirm the password (hidden)');

        if ($password === '') {
            $this->error('The password cannot be empty.');

            return self::FAILURE;
        }

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $passwordValidator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', 'min:12']]
        );

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Set explicitly: role is not mass assignable, by design.
        $user->role = UserRole::Owner;
        $user->email_verified_at = now();
        $user->save();

        // The password is never echoed back, not even masked.
        $this->info("Owner created: {$user->email} (id {$user->id}).");
        $this->line('Two-factor authentication must be enrolled at /two-factor before the dashboard is reachable.');

        return self::SUCCESS;
    }
}
