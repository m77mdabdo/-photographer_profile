<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use Tests\TestCase;

/**
 * When the dashboard refuses something, it says why — and it must say it in
 * the panel's language. Until the Help Center audit (Oct 2026) every reason
 * in app/Policies was an English literal, so the Arabic panel explained its
 * refusals in English.
 */
class RefusalsAreTranslatedTest extends TestCase
{
    public function test_no_policy_refuses_with_a_literal_sentence(): void
    {
        foreach (glob(app_path('Policies/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                "/Response::deny\\(\\s*(sprintf\\()?\\s*'/",
                $source,
                basename($file).' refuses with an untranslated sentence; use __(\'studio.refuse.…\')'
            );
        }
    }

    public function test_every_reason_has_an_arabic_sentence(): void
    {
        $en = (array) (require lang_path('en/studio.php'))['refuse'];
        $ar = (array) (require lang_path('ar/studio.php'))['refuse'];

        $this->assertSame(array_keys($en), array_keys($ar));

        foreach ($ar as $key => $sentence) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $sentence, "refuse.$key is not Arabic");
            $this->assertNotSame($en[$key], $sentence);
        }
    }

    /**
     * Sign-in refusals, found by performing them in Arabic (help-tour-check):
     * Fortify's messages are JSON-keyed and lang/ar.json did not exist, and
     * the error box's title was an English literal.
     */
    public function test_sign_in_refusals_read_in_arabic(): void
    {
        app()->setLocale('ar');

        foreach ([
            'The provided two factor authentication code was invalid.',
            'The provided two factor recovery code was invalid.',
            'The provided password was incorrect.',
        ] as $message) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __($message), "untranslated: $message");
        }

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', __('auth.failed'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', trans_choice('studio.auth.errors_title', 1));
        $this->assertStringNotContainsString('There ', (string) file_get_contents(resource_path('views/components/auth-errors.blade.php')));
    }

    public function test_the_cinematic_policy_names_cinematic_sections(): void
    {
        app()->setLocale('en');

        $this->assertSame('Only an Owner can permanently delete cinematic sections.', __('studio.refuse.force_cinematic_sections'));
        $this->assertStringNotContainsString('services', (string) file_get_contents(app_path('Policies/CinematicSectionPolicy.php')));
    }
}
