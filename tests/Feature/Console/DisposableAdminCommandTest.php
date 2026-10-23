<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\DisposableAdmin;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The command exists because the previous way to get the browser harness into
 * the admin was to overwrite the real owner's password and re-enrol their
 * second factor. These tests are the guarantees that made that acceptable to
 * replace rather than repeat.
 */
class DisposableAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function credentialsPath(): string
    {
        return base_path(DisposableAdmin::CREDENTIALS_PATH);
    }

    /**
     * Start from no credentials file.
     *
     * Without this, "the refused run wrote nothing" is only true if the
     * machine happened to be clean — and a developer who ran the command by
     * hand an hour ago makes this test fail for a reason that has nothing to
     * do with the code. It failed exactly that way the first time it ran.
     */
    /**
     * …but a clean start must not cost the developer their file. Deleting it
     * outright signed every running browser check out of the dashboard
     * mid-run, because the account it describes lives in the development
     * database, which these tests never touch. So it is set aside, and put
     * back afterwards.
     */
    private ?string $setAside = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (is_file($this->credentialsPath())) {
            $this->setAside = $this->credentialsPath().'.set-aside-'.getmypid();
            rename($this->credentialsPath(), $this->setAside);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->credentialsPath())) {
            @unlink($this->credentialsPath());
        }

        if ($this->setAside !== null && is_file($this->setAside)) {
            rename($this->setAside, $this->credentialsPath());
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------- 1

    /**
     * The whole safety argument. Anywhere but local, it refuses.
     */
    public function test_it_refuses_to_run_outside_local(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            $this->artisan('admin:disposable')
                ->expectsOutputToContain('only runs when APP_ENV=local')
                ->assertExitCode(1);

            $this->assertSame(0, User::query()->count(), "a user was created in {$environment}");
            $this->assertFileDoesNotExist($this->credentialsPath());
        }
    }

    public function test_even_delete_refuses_outside_local(): void
    {
        $user = User::factory()->create(['email' => 'real@studio.test']);

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('admin:disposable', ['--delete' => true])->assertExitCode(1);

        $this->assertNotNull(User::query()->find($user->id));
    }

    /**
     * NEVER TOUCHES AN EXISTING USER.
     *
     * Every column of every pre-existing account is compared before and after,
     * because the failure this guards against is not "it deleted somebody" —
     * it is the quiet one, where a password hash or a two-factor secret is
     * rewritten and nobody notices until they try to log in.
     */
    public function test_it_never_alters_an_existing_user(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $owner = User::factory()->create([
            'email' => 'owner@studio.test',
            'password' => Hash::make('the-real-password'),
        ]);
        $owner->forceFill([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('REALSECRET'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $editor = User::factory()->create(['email' => 'editor@studio.test']);

        $before = User::query()->orderBy('id')->get()->map->getAttributes()->toArray();

        $this->artisan('admin:disposable')->assertExitCode(0);
        $this->artisan('admin:disposable', ['--delete' => true])->assertExitCode(0);

        $after = User::query()->orderBy('id')->get()->map->getAttributes()->toArray();

        $this->assertEquals($before, $after, 'an existing account was modified');

        // Spelled out, because these are the two that would actually hurt.
        $this->assertTrue(Hash::check('the-real-password', $owner->fresh()->password));
        $this->assertSame('REALSECRET', decrypt($owner->fresh()->two_factor_secret));

        $this->assertNotNull(User::query()->find($editor->id));
    }

    public function test_it_creates_a_usable_owner_and_writes_nothing_sensitive_to_stdout(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->artisan('admin:disposable')->assertExitCode(0);

        $user = User::query()->sole();

        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->two_factor_confirmed_at, 'the harness would land on the enrolment page');
        $this->assertNotNull($user->two_factor_secret);

        // A reserved TLD, so it can never collide with or reach a real address.
        $this->assertStringEndsWith('@harness.invalid', $user->email);

        // --- the file ------------------------------------------------------
        $this->assertFileExists($this->credentialsPath());

        $credentials = json_decode((string) file_get_contents($this->credentialsPath()), true);

        $this->assertSame($user->id, $credentials['id']);
        $this->assertTrue(Hash::check($credentials['password'], $user->password));
        $this->assertSame($credentials['totp_secret'], decrypt($user->two_factor_secret));

        // Owner-only on disk: it holds a password and a TOTP seed.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->credentialsPath())), -4));

        // --- and none of it on stdout ---------------------------------------
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringNotContainsString($credentials['password'], $output);
        $this->assertStringNotContainsString($credentials['totp_secret'], $output);
    }

    public function test_delete_removes_the_account_and_the_file(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->artisan('admin:disposable')->assertExitCode(0);

        $this->assertSame(1, User::query()->count());

        $this->artisan('admin:disposable', ['--delete' => true])->assertExitCode(0);

        $this->assertSame(0, User::query()->count());
        $this->assertFileDoesNotExist($this->credentialsPath());
    }

    /**
     * A run that was killed before it wrote the file still gets cleaned up:
     * --delete falls back to the reserved domain, which no real account can
     * be in.
     */
    public function test_delete_cleans_up_an_orphan_with_no_credentials_file(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->artisan('admin:disposable')->assertExitCode(0);

        @unlink($this->credentialsPath());

        $keep = User::factory()->create(['email' => 'keeper@studio.test']);

        $this->artisan('admin:disposable', ['--delete' => true])->assertExitCode(0);

        $this->assertSame(0, User::query()->where('email', 'like', '%@harness.invalid')->count());
        $this->assertNotNull(User::query()->find($keep->id));
    }
}
