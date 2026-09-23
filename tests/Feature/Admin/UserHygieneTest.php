<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards against throwaway accounts surviving a verification run.
 *
 * Scope, stated plainly: this inspects whichever database the suite is
 * configured against (photographer_testing), not the development database.
 * It catches a seeder or factory that starts minting @example.test users; it
 * cannot see an account somebody created by hand in dev.
 */
class UserHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_account_uses_a_throwaway_example_test_address(): void
    {
        User::factory()->count(3)->create();

        $offenders = User::query()
            ->where('email', 'like', '%@example.test')
            ->pluck('email')
            ->all();

        $this->assertSame(
            [],
            $offenders,
            'Throwaway verification accounts must never survive: '.implode(', ', $offenders)
        );
    }

    public function test_every_user_has_a_role_that_resolves_to_a_known_case(): void
    {
        User::factory()->count(3)->create();

        $valid = array_map(static fn (UserRole $case): string => $case->value, UserRole::cases());

        // Read raw, so a column holding something the enum cannot parse is
        // caught rather than silently becoming null.
        $rawRoles = DB::table('users')->pluck('role', 'id');

        foreach ($rawRoles as $id => $role) {
            $this->assertContains(
                $role,
                $valid,
                "User {$id} holds an unknown role: ".var_export($role, true)
            );
        }

        foreach (User::all() as $user) {
            $this->assertInstanceOf(UserRole::class, $user->role, "User {$user->id} role did not resolve");
        }
    }

    public function test_the_factory_does_not_mint_example_test_addresses(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertStringNotContainsString('@example.test', User::factory()->make()->email);
        }
    }
}
