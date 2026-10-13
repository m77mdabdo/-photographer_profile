<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Throttle state is cached; a leaked counter would make these tests
        // depend on each other's order.
        RateLimiter::clear('login');
    }

    private function owner(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->role = UserRole::Owner;
        $user->save();

        return $user->refresh();
    }

    private function ownerWithTwoFactor(): User
    {
        $user = $this->owner();
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETVALUE'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    // -----------------------------------------------------------------

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_a_user_without_confirmed_two_factor_is_sent_to_setup(): void
    {
        $user = $this->owner();

        $this->assertFalse($user->hasTwoFactorEnabled());

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_a_user_with_confirmed_two_factor_reaches_the_dashboard(): void
    {
        $user = $this->ownerWithTwoFactor();

        $this->assertTrue($user->hasTwoFactorEnabled());

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_a_user_with_an_unrecognised_role_is_forbidden(): void
    {
        $user = $this->ownerWithTwoFactor();

        // Written past the model so the cast cannot sanitise it, exactly as a
        // stale or tampered row would look.
        DB::table('users')->where('id', $user->id)->update(['role' => 'intern']);

        $this->actingAs($user->fresh())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_a_sixth_failed_login_in_a_minute_is_throttled(): void
    {
        $user = $this->owner(['email' => 'studio@example.com']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login'), [
                'email' => $user->email,
                'password' => 'not-the-password',
            ])->assertStatus(302);
        }

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(429);
    }

    public function test_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_role_cannot_be_changed_by_mass_assignment(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Editor, $user->role);

        $user->fill(['name' => 'Renamed', 'role' => UserRole::Owner->value]);
        $user->save();

        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertSame(UserRole::Editor, $user->fresh()->role, 'role must not be fillable');

        // Nor through create().
        $created = User::create([
            'name' => 'Another',
            'email' => 'another@example.com',
            'password' => 'password-password',
            'role' => UserRole::Owner->value,
        ]);

        $this->assertSame(UserRole::Editor, $created->fresh()->role);
    }

    public function test_password_resets_do_not_leak_whether_an_account_exists(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'known@example.com']);

        $known = $this->post(route('password.email'), ['email' => 'known@example.com']);
        Notification::assertSentTo($user, ResetPassword::class);

        $unknown = $this->post(route('password.email'), ['email' => 'stranger@example.com']);

        // Exactly one notification in total: the stranger got nothing.
        Notification::assertSentTimes(ResetPassword::class, 1);

        // And the two responses are indistinguishable to the caller.
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'the status message must not reveal whether the address is registered'
        );
    }
}
