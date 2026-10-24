<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 34c — THE LANGUAGE, ON THE FRONT DOOR.
 *
 * 34b put the switcher in the panel, which is one step too late: an owner who
 * reads only Arabic meets the sign-in page in English and cannot reach the
 * control that would fix it, because the control is behind the door.
 *
 * Every page in the Fortify flow is covered, not only sign-in, because the
 * pages somebody reaches when something has gone wrong — a forgotten
 * password, a lost phone, an enrolment half finished — are exactly the ones
 * where going back to the start to change language is not an option.
 * ───────────────────────────────────────────────────────────────────────────
 */
class FrontDoorLocaleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private function owner(bool $twoFactor = true): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => $twoFactor ? encrypt('secret') : null,
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
        ]);
    }

    /**
     * The six pages, and how each is reached. Fortify owns five of them; the
     * enrolment page is this project's own route, outside both admins, which
     * is why Part B gave it its own address.
     *
     * @return array<string, array{0: string, 1: bool}> [path, needs a signed-in user]
     */
    public static function frontDoorPages(): array
    {
        return [
            'sign-in' => ['/login', false],
            'reset request' => ['/forgot-password', false],
            'reset form' => ['/reset-password/a-token?email=owner%40example.test', false],
            'two-factor challenge' => ['/two-factor-challenge', false],
            'two-factor setup' => ['/two-factor', true],
            'confirm password' => ['/user/confirm-password', true],
        ];
    }

    /**
     * The challenge page needs a half-signed-in session: the password has been
     * accepted and the second factor has not been given yet. Fortify keeps
     * that state in the session rather than in the guard, so this is how a
     * test stands where a person stands.
     */
    private function visit(string $path, bool $authenticated): TestResponse
    {
        if ($path === '/two-factor-challenge') {
            $user = $this->owner();

            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect('/two-factor-challenge');

            return $this->get($path);
        }

        if ($authenticated) {
            $this->actingAs($this->owner());
        }

        return $this->get($path);
    }

    // ----------------------------------------------------------------- 1

    /** Test 1: it is on all of them. */
    #[DataProvider('frontDoorPages')]
    public function test_the_switcher_is_on_every_front_door_page(string $path, bool $authenticated): void
    {
        $html = $this->visit($path, $authenticated)->assertOk()->getContent();

        $this->assertStringContainsString('data-locale-switcher', $html, "{$path} has no switcher");
        $this->assertStringContainsString('class="auth-locale"', $html, "{$path} is not using the front door's register");
        $this->assertStringContainsString('action="'.route('locale.store').'"', $html);

        foreach (Locale::codes() as $code) {
            $this->assertMatchesRegularExpression(
                '/<button[^>]*name="locale"[^>]*value="'.$code.'"/',
                $html,
                "{$path} offers no {$code} button",
            );
        }

        // D — named in both languages, and announced with what it changes.
        $this->assertStringContainsString('العربية', $html);
        $this->assertStringContainsString('English', $html);
        $this->assertStringContainsString(Locale::translatedName('ar'), $html);
        $this->assertStringContainsString(__('studio.locale.interface'), $html);

        /*
         * NOT inside the identity panel, which is aria-hidden: a focusable
         * control in there is reachable by Tab and invisible to a screen
         * reader. Asserted by position — the switcher comes after <main>.
         */
        $this->assertGreaterThan(
            (int) strpos($html, 'auth-split__identity'),
            (int) strpos($html, 'auth-locale'),
            "{$path}: the switcher is in the aria-hidden identity panel",
        );
        $this->assertGreaterThan(
            (int) strpos($html, 'id="auth-main"'),
            (int) strpos($html, 'auth-locale'),
        );
    }

    // ----------------------------------------------------------------- 2

    /**
     * Test 2: the page comes back, and so does what the URL was carrying.
     *
     * The reset address is the case that matters. Fortify puts the token in
     * the PATH and the address in the QUERY, and the form needs both — a
     * switcher that dropped the query would hand somebody a reset form with
     * an empty email field and no way to know what was lost.
     */
    #[DataProvider('frontDoorPages')]
    public function test_switching_preserves_the_page_and_its_token(string $path, bool $authenticated): void
    {
        $response = $this->post(route('locale.store'), ['locale' => 'ar', 'return_to' => $path]);

        $response->assertRedirect($path)->assertCookie(Locale::COOKIE, 'ar', false);
    }

    /** The same thing said the long way, on a real reset link. */
    public function test_a_real_reset_link_survives_the_switch(): void
    {
        $user = $this->owner();
        $token = Password::broker()->createToken($user);

        $link = '/reset-password/'.$token.'?email='.urlencode($user->email);

        $this->get($link)->assertOk();

        $this->post(route('locale.store'), ['locale' => 'ar', 'return_to' => $link])
            ->assertRedirect($link);

        // And the page it lands on is the form, in Arabic, with the address kept.
        $html = $this->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get($link)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString($user->email, $html);
        $this->assertStringContainsString($token, $html);
    }

    /**
     * The enrolment page is a private surface, which it was NOT until this
     * task — see Locale::PRIVATE_PREFIXES. Before the fix this redirected to
     * /ar/two-factor, which is not a route.
     */
    public function test_the_enrolment_page_is_treated_as_private(): void
    {
        $this->assertTrue(Locale::isPrivatePath('/two-factor'));

        $this->actingAs($this->owner())
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/two-factor'])
            ->assertRedirect('/two-factor');
    }

    // ----------------------------------------------------------------- 3

    /** Test 3: direction and content agree, on all six, in both locales. */
    #[DataProvider('frontDoorPages')]
    public function test_direction_and_language_agree_after_switching(string $path, bool $authenticated): void
    {
        foreach (Locale::codes() as $code) {
            /*
             * The cookie per request, and the application left alone.
             *
             * This called $this->refreshApplication() between locales, which
             * passed in isolation and broke SIX tests in other files when the
             * whole suite ran: rebuilding the container mid-test drops
             * RefreshDatabase's transaction, so rows written afterwards are
             * committed and the next test sees a database with seven users in
             * it. The failures surfaced in DisposableAdminCommandTest, which
             * counts users and had nothing to do with this.
             */
            $this->app->setLocale($code);
            $this->withUnencryptedCookie(Locale::COOKIE, $code);

            $html = $this->visit($path, $authenticated)->assertOk()->getContent();

            $this->assertStringContainsString(
                'dir="'.Locale::direction($code).'"',
                $html,
                "{$path} is not ".Locale::direction($code)." in {$code}",
            );
            $this->assertStringContainsString('lang="'.Locale::html($code).'"', $html);

            // The control agrees with the document it is on.
            $this->assertMatchesRegularExpression(
                '/value="'.$code.'"[^>]*aria-current="true"/s',
                $html,
                "{$path}: the switcher does not show {$code} as current",
            );

            /*
             * E — and the CONTENT is in that language too, not merely the
             * direction. Task 32 found these pages mirrored with English in
             * them, which is the failure this pairing exists to catch.
             */
            if ($code === 'ar') {
                $this->assertMatchesRegularExpression(
                    '/\p{Arabic}/u',
                    strip_tags($html),
                    "{$path} is RTL with no Arabic in it",
                );
                $this->assertStringNotContainsString('Sign in', strip_tags($html));
            }
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * THE PAGE'S OWN SUBMIT BUTTON IS STILL FINDABLE.
     *
     * This control adds two submit buttons to every front-door page, ahead of
     * the form's own in the DOM, and that broke the browser harness the
     * moment it landed: loginAsDisposableAdmin() clicked
     * `button[type="submit"]`, which was now "English". It posted the locale
     * form, came back to /login signed out, and the run then audited the
     * sign-in page twenty-four times under the panel's names and called them
     * passes.
     *
     * The harness is fixed — it clicks the form's own submit and then asks
     * whether the panel is actually on the screen — but the shape that caused
     * it is asserted here, where it is cheap: on each page there is exactly
     * ONE submit button outside the switcher, and it belongs to that page's
     * form. Anything automating this door can rely on that.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[DataProvider('frontDoorPages')]
    public function test_the_page_keeps_exactly_one_submit_of_its_own(string $path, bool $authenticated): void
    {
        $html = $this->visit($path, $authenticated)->assertOk()->getContent();

        $withoutSwitcher = (string) preg_replace(
            '#<form[^>]*data-locale-switcher.*?</form>#s',
            '',
            $html,
        );

        preg_match_all('/<button[^>]*type="submit"/', $withoutSwitcher, $own);
        preg_match_all('/<button[^>]*type="submit"/', $html, $all);

        $this->assertCount(1, $own[0], "{$path} does not have exactly one submit of its own");
        $this->assertCount(3, $all[0], "{$path}: the switcher no longer contributes two submits");
    }

    // ----------------------------------------------------------------- 4

    /** Test 4: one cookie, three switchers. */
    public function test_it_writes_the_same_cookie_as_the_other_two(): void
    {
        $fromDoor = $this->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/login']);
        $fromPanel = $this->actingAs($this->owner())
            ->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/admin']);
        $fromPublic = $this->post(route('locale.store'), ['locale' => 'ar', 'return_to' => '/work']);

        $cookieOf = function ($response) {
            return collect($response->headers->getCookies())
                ->firstWhere(fn ($c): bool => $c->getName() === Locale::COOKIE);
        };

        $door = $cookieOf($fromDoor);
        $panel = $cookieOf($fromPanel);
        $public = $cookieOf($fromPublic);

        foreach ([$door, $panel, $public] as $cookie) {
            $this->assertNotNull($cookie);
            $this->assertSame('ar', $cookie->getValue());
            $this->assertSame('/', $cookie->getPath());
            $this->assertFalse($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
        }

        // And it is the cookie SetLocale reads, not a lookalike.
        $this->assertSame(Locale::COOKIE, $door->getName());
    }

    /** All three render the one component, so the contract cannot drift. */
    public function test_the_three_switchers_are_one_component(): void
    {
        $component = base_path('resources/views/components/locale-switch.blade.php');

        $this->assertFileExists($component);

        foreach ([
            'resources/views/layouts/auth.blade.php',
            // The public header renders it inside the globe (Phase 2).
            'resources/views/components/globe-switch.blade.php',
            'resources/views/filament/studio/locale-switch.blade.php',
        ] as $view) {
            $this->assertStringContainsString(
                '<x-locale-switch',
                (string) file_get_contents(base_path($view)),
                "{$view} does not render the shared control",
            );
        }
    }
}
