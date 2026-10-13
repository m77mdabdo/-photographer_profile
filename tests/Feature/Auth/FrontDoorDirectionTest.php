<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 0 — DIRECTION AND CONTENT NEVER DISAGREE ON THE FRONT DOOR.
 *
 * THE DEFECT. The layouts set dir from Locale::direction(), which reads the
 * VISITOR'S stored preference. Four of Fortify's five pages had their English
 * typed straight into the markup with no __() at all, and lang/ar carried no
 * file for Laravel's own auth, passwords, pagination or validation messages —
 * so those fell back to the framework's English. With the locale cookie set to
 * ar, the result was an English form inside dir="rtl": sentence-final full
 * stops rendered at the visual start of the line and the layout mirrored
 * around text that had not moved.
 *
 * THE SECOND DEFECT, found while reproducing the first. forgot-password,
 * reset-password, confirm-password and two-factor-challenge were all rendered
 * by <x-layouts::admin> — the SIGNED-IN working layout. An unauthenticated
 * visitor who asked for a password link was served the entire admin shell,
 * including a navigation list that still named screens from before the panel
 * was rebuilt. two-factor-challenge is reached HALF-signed-in, which is worse.
 *
 * THE CHOICE (0c). Translate the pages, rather than letting direction follow
 * the content's language.
 *
 * Making dir follow content would have been a smaller change and it is the
 * wrong one here. These four pages survive the step-10 deletion of the custom
 * admin, so they are the shipped product, not scaffolding — and an Arabic
 * owner reading an English login form in a left-to-right box has not been
 * served, they have been told their language is for the public site only.
 * Direction follows the reader, as it does everywhere else on this site;
 * content now follows direction, which is what was missing.
 *
 * The LTR islands stay LTR, per element rather than per page: an email
 * address, a password and a six-digit code read left-to-right in every
 * language, so each input carries its own dir="ltr".
 * ───────────────────────────────────────────────────────────────────────────
 */
class FrontDoorDirectionTest extends TestCase
{
    use RefreshDatabase;

    /** Every front-door page, and how to reach it. */
    private const PAGES = [
        'login' => '/login',
        'forgot password' => '/forgot-password',
        'reset password' => '/reset-password/a-token?email=owner%40example.com',
    ];

    /**
     * Latin letters that are allowed to appear in an Arabic render.
     *
     * The studio's NAME is a proper noun and may legitimately be Latin — the
     * seeded settings name is, and so is the fallback to config('app.name').
     * Everything else in these pages is interface text.
     */
    private function visibleText(string $html): string
    {
        $body = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html);
        $body = (string) preg_replace('#<!--.*?-->#s', '', $body);

        // The <title> and meta are not visible body text.
        $body = (string) preg_replace('#<head>.*?</head>#s', '', $body);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
    }

    // ----------------------------------------------------------------- 0a

    /** Each page is RTL and carries no interface English. */
    public function test_no_front_door_page_mixes_rtl_with_english_copy(): void
    {
        \App\Support\Settings::set('site.name', 'استوديو الجوهرة');
        \App\Support\Settings::flush();

        foreach (self::PAGES as $name => $path) {
            $html = $this->withUnencryptedCookie(Locale::COOKIE, 'ar')->get($path)->getContent();

            $this->assertStringContainsString('dir="rtl"', $html, "$name is not RTL under the ar cookie");
            $this->assertStringContainsString('lang="ar"', $html, "$name does not declare Arabic");

            $text = $this->visibleText($html);

            /*
             * THE ASSERTION THAT WOULD HAVE CAUGHT THIS. Any run of three or
             * more Latin letters in the visible text of an Arabic page is
             * interface English — the one legitimate exception, the studio's
             * own name, is set to Arabic above so there is nothing to excuse.
             */
            preg_match_all('/[A-Za-z]{3,}/', $text, $latin);

            $this->assertSame(
                [],
                array_values(array_unique($latin[0])),
                "$name renders English inside dir=\"rtl\": ".implode(', ', array_unique($latin[0]))
            );
        }
    }

    /** The two-factor challenge too, which needs a half-signed-in session. */
    public function test_the_two_factor_challenge_is_arabic_under_the_ar_cookie(): void
    {
        \App\Support\Settings::set('site.name', 'استوديو الجوهرة');
        \App\Support\Settings::flush();

        $user = User::factory()->create([
            'role' => UserRole::Owner,
            'password' => bcrypt('a-long-enough-password'),
            'two_factor_secret' => encrypt('SECRETVALUE'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->post('/login', ['email' => $user->email, 'password' => 'a-long-enough-password']);

        $html = $this->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get('/two-factor-challenge')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);

        preg_match_all('/[A-Za-z]{3,}/', $this->visibleText($html), $latin);

        $this->assertSame([], array_values(array_unique($latin[0])));
    }

    // ----------------------------------------------------------------- 0b

    /**
     * NO FRONT-DOOR PAGE SERVES THE ADMIN SHELL.
     *
     * Asserted by the navigation's absence rather than by naming the layout,
     * because what matters is that an unauthenticated visitor cannot read the
     * studio's back-office structure.
     */
    public function test_no_front_door_page_leaks_the_admin_navigation(): void
    {
        foreach (self::PAGES as $name => $path) {
            $html = $this->get($path)->assertOk()->getContent();

            foreach (['admin-nav', 'admin-shell', 'Galleries', 'Redirects', 'Enquiries'] as $trace) {
                $this->assertStringNotContainsString(
                    $trace,
                    $html,
                    "$name leaks the admin chrome: found \"$trace\""
                );
            }

            // And it IS the front door's layout.
            $this->assertStringContainsString('auth-split', $html, "$name is not on the auth layout");
        }
    }

    // ----------------------------------------------------------------- 0c

    /** Every one of the four is on the auth layout, in source. */
    public function test_every_fortify_view_uses_the_auth_layout(): void
    {
        foreach (glob(resource_path('views/auth/*.blade.php')) as $view) {
            $source = (string) file_get_contents($view);

            // Comments may discuss the admin layout; the opening tag may not be it.
            $source = (string) preg_replace('#\{\{--.*?--\}\}#s', '', $source);

            $this->assertStringContainsString(
                '<x-layouts::auth',
                $source,
                basename($view).' is not rendered by the auth layout'
            );
            $this->assertStringNotContainsString('<x-layouts::admin', $source, basename($view));
        }
    }

    /** No Blade in these views carries a bare English string. */
    public function test_no_fortify_view_has_hardcoded_copy(): void
    {
        foreach (glob(resource_path('views/auth/*.blade.php')) as $view) {
            $source = (string) preg_replace(
                '#\{\{--.*?--\}\}#s',
                '',
                (string) file_get_contents($view)
            );

            // Text between tags, which is what a visitor reads.
            preg_match_all('#>([^<>{}]*[A-Za-z]{3,}[^<>{}]*)<#', $source, $matches);

            $copy = array_values(array_filter(
                array_map('trim', $matches[1]),
                /*
                 * A Blade directive is not copy, and nor is the leftover of
                 * one. @csrf sits between two tags; and the `>` in an arrow
                 * operator looks like a tag boundary, so
                 * `@if (! auth()->user()->two_factor_secret)` presented
                 * "two_factor_secret)" as prose. Copy does not contain code
                 * punctuation — that single rule covers both.
                 */
                static fn (string $text): bool => $text !== ''
                    && ! str_starts_with($text, '@')
                    && preg_match('/[(){}$]|->|::/', $text) !== 1,
            ));

            $this->assertSame(
                [],
                $copy,
                basename($view).' has copy typed into the markup: '.implode(' | ', $copy)
            );
        }
    }

    // ----------------------------------------------------------------- 0d

    /**
     * THE FRAMEWORK'S OWN MESSAGES ARE ARABIC UNDER ar.
     *
     * Laravel 12 does not publish its lang files; it falls back to the
     * English inside the vendor directory for any locale with no file of its
     * own. So every validation error, every "these credentials do not match"
     * and every pagination control was English on an RTL page, and no amount
     * of translating Blade would have fixed it.
     */
    public function test_laravel_s_own_messages_are_translated(): void
    {
        $this->app->setLocale('ar');

        $cases = [
            'auth.failed' => [],
            'auth.password' => [],
            'auth.throttle' => ['seconds' => 30],
            'passwords.sent' => [],
            'passwords.token' => [],
            'passwords.user' => [],
            'pagination.previous' => [],
            'pagination.next' => [],
            'validation.required' => ['attribute' => 'البريد'],
            'validation.email' => ['attribute' => 'البريد'],
            'validation.max.string' => ['attribute' => 'العنوان', 'max' => 255],
            'validation.unique' => ['attribute' => 'الرابط'],
            'validation.confirmed' => ['attribute' => 'كلمة المرور'],
        ];

        foreach ($cases as $key => $replace) {
            $message = __($key, $replace);

            $this->assertNotSame($key, $message, "$key does not resolve at all");

            $this->assertDoesNotMatchRegularExpression(
                '/[A-Za-z]{3,}/',
                (string) preg_replace('/&[a-z]+;/', '', (string) $message),
                "$key is still English under ar: $message"
            );
        }
    }

    /**
     * EVERY validation rule the framework ships is translated, not the dozen
     * this project happens to use.
     *
     * A partial file is worse than none: Laravel resolves against the Arabic
     * array first and only falls back for an ABSENT key, so a half-finished
     * translation answers in Arabic for `required` and English for `max`.
     */
    public function test_the_arabic_validation_file_is_complete(): void
    {
        $arabic = require lang_path('ar/validation.php');
        $english = require base_path(
            'vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'
        );

        /*
         * Sorted: the SET of rules must match, not the order they are written
         * in. The Arabic file groups the sized rules together because their
         * four variants read as one decision, where the framework interleaves
         * them alphabetically.
         */
        $arabicKeys = array_keys($arabic);
        $englishKeys = array_keys($english);
        sort($arabicKeys);
        sort($englishKeys);

        $this->assertSame(
            $englishKeys,
            $arabicKeys,
            'the Arabic validation file and the framework disagree about which rules exist'
        );

        foreach ($english as $rule => $value) {
            if (! is_array($value) || $rule === 'custom' || $rule === 'attributes') {
                continue;
            }

            $this->assertSame(
                array_keys($value),
                array_keys($arabic[$rule]),
                "validation.$rule is missing a size variant in Arabic"
            );
        }
    }

    /** English is untouched: it still comes from the framework. */
    public function test_english_is_not_duplicated_into_lang_en(): void
    {
        foreach (['auth', 'passwords', 'pagination', 'validation'] as $file) {
            $this->assertFileDoesNotExist(
                lang_path("en/{$file}.php"),
                "lang/en/{$file}.php duplicates the framework's English — two copies, "
                .'and the local one silently wins on upgrade'
            );
        }
    }

    // ----------------------------------------------------------------- misc

    /** The LTR islands stay LTR: an email address is not an Arabic string. */
    public function test_the_credential_inputs_are_left_to_right(): void
    {
        $html = $this->withUnencryptedCookie(Locale::COOKIE, 'ar')->get('/login')->getContent();

        preg_match_all('#<input[^>]*name="(email|password)"[^>]*>#', $html, $inputs);

        $this->assertCount(2, $inputs[0], 'the login form should have an email and a password input');

        foreach ($inputs[0] as $input) {
            $this->assertStringContainsString('dir="ltr"', $input, "an RTL credential input: $input");
        }
    }

    /** And English still reads as English. */
    public function test_the_pages_are_still_english_without_the_cookie(): void
    {
        foreach (self::PAGES as $name => $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('dir="ltr"', $html, "$name is not LTR by default");
            $this->assertStringContainsString('lang="en"', $html, $name);
        }

        $this->assertStringContainsString('Reset your password', $this->get('/forgot-password')->getContent());
    }
}
