<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TwoFactorEnforcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TWO-FACTOR ENFORCEMENT IS AN ENVIRONMENT DECISION, AND PRODUCTION DOES NOT
 * GET A VOTE.
 *
 * The client signs in with a password while the site is being built. Nothing
 * about the requirement was deleted — EnsureTwoFactorEnabled, the setup page,
 * the challenge and the existing tests are all unchanged — so what has to be
 * proven here is narrow and specific:
 *
 *   on   an admin with no confirmed factor reaches neither panel
 *   off  the same admin reaches both
 *   unset  means ON, because a missing or misspelt env var must fail secure
 *   production + off  refuses to boot at all
 *   and the login throttle, which is now the ONLY thing between a password
 *   and a brute force, still fires
 * ───────────────────────────────────────────────────────────────────────────
 */
class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** An admin who has never enrolled a second factor. */
    private function adminWithoutTwoFactor(): User
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $user->refresh();
    }

    /** An admin who enrolled and confirmed one. */
    private function adminWithTwoFactor(): User
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);

        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETVALUE'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    // ----------------------------------------------------------------- 1

    /**
     * ON: neither panel is reachable without a confirmed factor.
     *
     * TASK 33 SWAPPED THE ADDRESSES and Task 34 Part B deleted the custom
     * admin, so there is one stack rather than two. These assertions covered
     * BOTH while both existed; they now cover the survivor and a deeper page
     * inside it, so this is still the middleware being tested and not one
     * route's own guard.
     */
    public function test_with_enforcement_on_an_admin_without_a_factor_is_sent_to_enrolment(): void
    {
        config(['auth.require_two_factor' => true]);

        $user = $this->adminWithoutTwoFactor();

        $this->actingAs($user)->get('/admin')->assertRedirect(route('two-factor.setup'));

        // And a deeper page, so it is the middleware and not one route.
        $this->actingAs($user)->get('/admin/projects')->assertRedirect(route('two-factor.setup'));
        $this->actingAs($user)->get('/admin/media')->assertRedirect(route('two-factor.setup'));
    }

    // ----------------------------------------------------------------- 2

    /** OFF: the same admin gets in. */
    public function test_with_enforcement_off_an_admin_without_a_factor_reaches_the_panel(): void
    {
        config(['auth.require_two_factor' => false]);

        $user = $this->adminWithoutTwoFactor();

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/projects')->assertOk();
        $this->actingAs($user)->get('/admin/media')->assertOk();

        // And the old addresses still translate, without asking for a factor
        // of their own: the redirect moves you, the panel decides.
        $this->actingAs($user)->get('/legacy/projects')->assertRedirect('/admin/projects');
    }

    /**
     * OFF CHANGES NOTHING ELSE. The role guard still refuses, and a guest is
     * still sent to the login page.
     *
     * The switch is about the second factor and only about the second factor;
     * a flag that quietly relaxed the other two guards would be the real
     * failure wearing this task's clothes.
     *
     * UserRole has exactly two cases and both are admin roles, so "not an
     * admin" is a row whose role column holds something the enum does not
     * know — which is what EnsureUserIsAdmin fails closed on.
     */
    public function test_enforcement_off_does_not_relax_any_other_guard(): void
    {
        config(['auth.require_two_factor' => false]);

        $outsider = $this->adminWithoutTwoFactor();

        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $outsider->getKey())
            ->update(['role' => 'subscriber']);

        $this->actingAs($outsider->fresh())->get('/admin')->assertForbidden();
        $this->actingAs($outsider->fresh())->get('/legacy')->assertForbidden();
    }

    /**
     * And a guest is still a guest.
     *
     * Its own test because actingAs() holds for the rest of the one it is
     * called in — asserting this after a signed-in assertion measured the
     * signed-in user again and passed for the wrong reason.
     */
    public function test_enforcement_off_still_sends_a_guest_to_the_login_page(): void
    {
        config(['auth.require_two_factor' => false]);

        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/legacy')->assertRedirect(route('login'));
    }

    /** And with it off, an admin who DID enrol is unaffected. */
    public function test_an_enrolled_admin_is_unaffected_either_way(): void
    {
        $user = $this->adminWithTwoFactor();

        config(['auth.require_two_factor' => true]);
        $this->actingAs($user)->get('/admin')->assertOk();

        config(['auth.require_two_factor' => false]);
        $this->actingAs($user)->get('/admin')->assertOk();
    }

    // ----------------------------------------------------------------- 3

    /**
     * THE DEFAULT IS ENFORCEMENT, AND SO IS EVERY MISTAKE.
     *
     * Read by evaluating config/auth.php itself with the environment variable
     * in each state, rather than by asserting about config() — which would
     * only prove what the test had just set.
     */
    public function test_the_default_with_no_env_var_is_on(): void
    {
        $this->assertTrue($this->configuredValue(null), 'a missing env var must mean enforcement');
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function envValues(): array
    {
        return [
            'absent' => [null, true],
            'blank' => ['', true],
            'whitespace' => ['   ', true],
            'misspelt false' => ['flase', true],
            'nonsense' => ['maybe', true],
            'true' => ['true', true],
            '1' => ['1', true],
            'false' => ['false', false],
            '0' => ['0', false],
            'off' => ['off', false],
            'no' => ['no', false],
        ];
    }

    /**
     * Only a correctly spelled false switches it off.
     *
     * The blank and whitespace cases are the ones that caught a real mistake
     * in the first version of this config: env() returns '' for a key that
     * exists with no value, and FILTER_VALIDATE_BOOLEAN reads both '' and ' '
     * as false — so an operator who blanked the value rather than deleting the
     * line would have disabled enforcement.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('envValues')]
    public function test_only_a_deliberate_false_switches_enforcement_off(?string $value, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->configuredValue($value),
            sprintf('AUTH_REQUIRE_TWO_FACTOR=%s should be %s', var_export($value, true), $expected ? 'ON' : 'off')
        );
    }

    /** Evaluate the real config file with the env var in a given state. */
    private function configuredValue(?string $value): bool
    {
        $previous = $_ENV['AUTH_REQUIRE_TWO_FACTOR'] ?? null;
        $previousServer = $_SERVER['AUTH_REQUIRE_TWO_FACTOR'] ?? null;

        try {
            /*
             * All three places Dotenv and phpunit.xml can put a value.
             * Unsetting only $_ENV left phpunit's own <env> entry visible
             * through getenv(), so the "absent" case read whatever the suite
             * had pinned instead of nothing.
             */
            if ($value === null) {
                unset($_ENV['AUTH_REQUIRE_TWO_FACTOR'], $_SERVER['AUTH_REQUIRE_TWO_FACTOR']);
                putenv('AUTH_REQUIRE_TWO_FACTOR');
            } else {
                $_ENV['AUTH_REQUIRE_TWO_FACTOR'] = $value;
                $_SERVER['AUTH_REQUIRE_TWO_FACTOR'] = $value;
                putenv('AUTH_REQUIRE_TWO_FACTOR='.$value);
            }

            /*
             * Laravel memoises env() reads inside the Env repository, so the
             * file is included directly rather than through config() — which
             * would return the value cached when the application booted.
             */
            $config = require config_path('auth.php');

            return $config['require_two_factor'];
        } finally {
            unset($_ENV['AUTH_REQUIRE_TWO_FACTOR'], $_SERVER['AUTH_REQUIRE_TWO_FACTOR']);
            putenv('AUTH_REQUIRE_TWO_FACTOR');

            if ($previous !== null) {
                $_ENV['AUTH_REQUIRE_TWO_FACTOR'] = $previous;
                putenv('AUTH_REQUIRE_TWO_FACTOR='.$previous);
            }

            if ($previousServer !== null) {
                $_SERVER['AUTH_REQUIRE_TWO_FACTOR'] = $previousServer;
            }
        }
    }

    // ----------------------------------------------------------------- 4

    /**
     * PRODUCTION WITH THE FACTOR OPTIONAL DOES NOT START.
     *
     * The condition is APP_ENV=production and enforcement off, and the guard
     * runs in AppServiceProvider::boot() — before a route is matched, so a web
     * request is a 500 and `php artisan migrate` on a deploy exits non-zero
     * with the same sentence. The deploy fails instead of the security.
     */
    public function test_the_production_guard_fires(): void
    {
        config(['auth.require_two_factor' => false]);

        try {
            TwoFactorEnforcement::assertSafeForEnvironment('production');

            $this->fail('a production application booted with two-factor optional');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AUTH_REQUIRE_TWO_FACTOR', $e->getMessage());
            $this->assertStringContainsString('production', $e->getMessage());

            // The message says what to do, not only what is wrong.
            $this->assertStringContainsString('set it to true', $e->getMessage());
        }
    }

    /** And it stays quiet in every other combination. */
    public function test_the_production_guard_does_not_fire_otherwise(): void
    {
        config(['auth.require_two_factor' => true]);

        TwoFactorEnforcement::assertSafeForEnvironment('production');
        TwoFactorEnforcement::assertSafeForEnvironment('local');

        config(['auth.require_two_factor' => false]);

        TwoFactorEnforcement::assertSafeForEnvironment('local');
        TwoFactorEnforcement::assertSafeForEnvironment('staging');
        TwoFactorEnforcement::assertSafeForEnvironment('testing');

        $this->addToAssertionCount(5);
    }

    /** The guard really is wired into the boot sequence, not merely written. */
    public function test_the_guard_is_called_at_boot(): void
    {
        $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString(
            'TwoFactorEnforcement::assertSafeForEnvironment();',
            $source,
            'the guard exists but nothing calls it'
        );

        // First statement in boot(), so nothing can run before it.
        $boot = substr($source, strpos($source, 'public function boot(): void'));

        $this->assertLessThan(
            strpos($boot, 'Blade::anonymousComponentPath'),
            strpos($boot, 'TwoFactorEnforcement::assertSafeForEnvironment();'),
            'the guard should be the first thing boot() does'
        );
    }

    // ----------------------------------------------------------------- 5

    /**
     * THE LOGIN THROTTLE STILL FIRES, AND IT MATTERS MORE NOW THAN IT DID.
     *
     * With a second factor mandatory, a guessed password was not enough. With
     * enforcement off it is, so the email+IP limiter is the only thing left.
     * Asserted with enforcement OFF deliberately — that is the configuration
     * in which it is load-bearing.
     */
    public function test_the_login_throttle_still_returns_429_on_the_sixth_attempt(): void
    {
        config(['auth.require_two_factor' => false]);

        RateLimiter::clear('');

        $user = $this->adminWithoutTwoFactor();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(302);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /** Fortify still offers enrolment, so anybody who wants it can have it. */
    public function test_the_two_factor_feature_is_still_available(): void
    {
        config(['auth.require_two_factor' => false]);

        $this->assertTrue(
            \Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::twoFactorAuthentication()),
            "Fortify's two-factor feature must stay on: an editor who wants a second factor must be able to enrol"
        );

        // And the setup page is still reachable by an admin with no factor.
        $this->actingAs($this->adminWithoutTwoFactor())
            ->get(route('two-factor.setup'))
            ->assertOk();
    }
}
