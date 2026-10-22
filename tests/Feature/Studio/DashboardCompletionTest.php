<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Filament\Pages\AccountSecurity;
use App\Filament\Pages\Help;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Enquiries\Pages\ViewInquiry;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\SiteCopy;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard gaps the master directive's audit found (Part 7), each
 * closed and pinned here:
 *
 *   - Site settings showed raw keys ("site.name") as labels
 *   - an enquiry's status could not be changed by anybody
 *   - there was no way to change a password inside the dashboard
 *   - two-factor recovery codes were accepted at sign-in and shown nowhere
 *   - there was no help inside the dashboard
 */
class DashboardCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(UserRole $role = UserRole::Owner, string $password = 'a-long-password-1'): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'password' => Hash::make($password),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_every_setting_has_a_plain_label_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $missing = array_values(array_filter(SiteCopy::keys(), fn (string $key): bool => Settings::labelFor($key) === $key));
            $this->assertSame([], $missing, "settings with no {$locale} label");
        }

        app()->setLocale('en');
        $this->assertSame('Studio name', Settings::labelFor('site.name'));
        $this->assertSame('Email address that receives enquiries', Settings::labelFor('contact.recipient'));
    }

    public function test_the_settings_page_shows_labels_not_keys(): void
    {
        $this->admin();
        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $this->get(Settings::getUrl())->assertOk()
            ->assertSee('Studio name')
            ->assertSee('Email address that receives enquiries');
    }

    public function test_an_editor_changes_an_enquirys_status(): void
    {
        $this->admin(UserRole::Editor);
        $enquiry = Inquiry::factory()->create(['status' => InquiryStatus::Read]);

        Livewire::test(ViewInquiry::class, ['record' => $enquiry->getKey()])
            ->callAction('changeStatus', ['status' => 'booked'])
            ->assertHasNoActionErrors();

        $this->assertSame(InquiryStatus::Booked, $enquiry->fresh()->status);

        Livewire::test(ViewInquiry::class, ['record' => $enquiry->getKey()])
            ->callAction('changeStatus', ['status' => 'nonsense'])
            ->assertHasActionErrors(['status']);
    }

    public function test_recovery_codes_need_the_password_and_regenerate(): void
    {
        $this->admin();

        Livewire::test(AccountSecurity::class)
            ->assertSet('codes', null)
            ->callAction('show', ['password' => 'wrong-password-here'])
            ->assertHasActionErrors(['password'])
            ->assertSet('codes', null);

        Livewire::test(AccountSecurity::class)
            ->callAction('show', ['password' => 'a-long-password-1'])
            ->assertSet('codes', ['aaaa-bbbb', 'cccc-dddd'])
            ->assertSee('aaaa-bbbb');

        Livewire::test(AccountSecurity::class)
            ->callAction('regenerate', ['password' => 'a-long-password-1'])
            ->assertHasNoActionErrors();

        $codes = auth()->user()->fresh()->recoveryCodes();
        $this->assertCount(8, $codes);
        $this->assertNotContains('aaaa-bbbb', $codes, 'the old codes still work after regenerating');
    }

    public function test_a_password_change_needs_the_current_password_and_twelve_characters(): void
    {
        $user = $this->admin();

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'short-pw', 'passwordConfirmation' => 'short-pw', 'currentPassword' => 'a-long-password-1'])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'a-new-long-password', 'passwordConfirmation' => 'a-new-long-password', 'currentPassword' => 'not-my-password'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'a-new-long-password', 'passwordConfirmation' => 'a-new-long-password', 'currentPassword' => 'a-long-password-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-new-long-password', $user->fresh()->password));
    }

    public function test_help_shows_the_guide_in_the_panels_language_and_strips_html(): void
    {
        $this->admin();

        $html = (string) $this->get(Help::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Signing in', $html);
        $this->assertStringContainsString('Cinematic sections (home page)', $html);
        $this->assertStringNotContainsString('<div dir="rtl"', $html);

        app()->setLocale('ar');
        $sections = (new Help())->sections();
        $this->assertGreaterThan(10, count($sections));
        $this->assertSame('1. تسجيل الدخول', $sections[0]['title']);
        foreach ($sections as $section) {
            $this->assertStringNotContainsString('<script', $section['html']);
            $this->assertStringNotContainsString('<div', $section['html']);
        }
    }
}
