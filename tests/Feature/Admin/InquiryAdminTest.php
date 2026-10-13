<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\InquiryDetail;
use App\Livewire\Admin\InquiryIndex;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InquiryAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();
    }

    /**
     * Two-factor is mandatory for the admin area, so a user without a
     * confirmed second factor is redirected to set one up rather than
     * reaching any page. Tests that make real HTTP requests need one.
     */
    private function admin(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }

    private function owner(): User
    {
        return $this->admin(UserRole::Owner);
    }

    private function editor(): User
    {
        return $this->admin(UserRole::Editor);
    }

    // ----------------------------------------------------------------- 9

    public function test_a_guest_cannot_reach_any_admin_inquiry_route(): void
    {
        $inquiry = Inquiry::factory()->create();

        foreach ([
            route('legacy.inquiries.index'),
            route('legacy.inquiries.show', $inquiry),
        ] as $url) {
            $response = $this->get($url);

            // Redirected to login, never served and never a 200.
            $this->assertNotSame(200, $response->getStatusCode(), "{$url} was served to a guest");
            $response->assertRedirect();
        }
    }

    /**
     * The policy's boundary, asserted directly.
     *
     * There is no "user with no role" to test with — the column is NOT NULL
     * and both roles are admin roles — so the meaningful boundary is the one
     * between them, and it is the destructive action that differs.
     */
    public function test_the_policy_separates_editing_from_erasing(): void
    {
        $inquiry = Inquiry::factory()->create();

        $editor = $this->editor();
        $owner = $this->owner();

        foreach ([$editor, $owner] as $user) {
            $this->assertTrue($user->can('viewAny', Inquiry::class));
            $this->assertTrue($user->can('update', $inquiry));
            $this->assertTrue($user->can('delete', $inquiry), 'a soft delete is recoverable, so an editor may');
        }

        $this->assertFalse($editor->can('forceDelete', $inquiry));
        $this->assertTrue($owner->can('forceDelete', $inquiry));
    }

    // ----------------------------------------------------------------- 10

    public function test_an_editor_can_change_status_and_add_notes(): void
    {
        $inquiry = Inquiry::factory()->create();

        Livewire::actingAs($this->editor())
            ->test(InquiryDetail::class, ['inquiry' => $inquiry])
            ->set('status', InquiryStatus::Qualified->value)
            ->call('updateStatus')
            ->assertHasNoErrors()
            ->set('note', 'Called back; two days in April, budget confirmed.')
            ->call('addNote')
            ->assertHasNoErrors();

        $inquiry->refresh();

        $this->assertSame(InquiryStatus::Qualified, $inquiry->status);
        $this->assertSame(1, $inquiry->notes()->count());
        $this->assertSame('Called back; two days in April, budget confirmed.', $inquiry->notes()->first()->body);
    }

    public function test_only_an_owner_can_erase_permanently(): void
    {
        $inquiry = Inquiry::factory()->create(['email' => 'frances@example.com']);

        // An Editor cannot, by policy.
        Livewire::actingAs($this->editor())
            ->test(InquiryDetail::class, ['inquiry' => $inquiry])
            ->call('erase')
            ->assertForbidden();

        $this->assertNotNull(Inquiry::withTrashed()->find($inquiry->id));

        // An Owner can, but not without typing the address.
        Livewire::actingAs($this->owner())
            ->test(InquiryDetail::class, ['inquiry' => $inquiry])
            ->set('confirmation', 'wrong@example.com')
            ->call('erase')
            ->assertHasErrors('confirmation');

        $this->assertNotNull(Inquiry::withTrashed()->find($inquiry->id));

        Livewire::actingAs($this->owner())
            ->test(InquiryDetail::class, ['inquiry' => $inquiry])
            ->set('confirmation', 'frances@example.com')
            ->call('erase');

        $this->assertNull(Inquiry::withTrashed()->find($inquiry->id), 'the row survived a confirmed erasure');
    }

    public function test_archiving_is_a_soft_delete_and_is_recoverable(): void
    {
        $inquiry = Inquiry::factory()->create();

        Livewire::actingAs($this->editor())
            ->test(InquiryDetail::class, ['inquiry' => $inquiry])
            ->call('archive');

        $this->assertNull(Inquiry::find($inquiry->id));
        $this->assertNotNull(Inquiry::withTrashed()->find($inquiry->id), 'the bin must be recoverable');
    }

    // ----------------------------------------------------------------- 11

    /**
     * Every field a stranger controls, rendered back into an admin page.
     */
    public function test_script_in_any_field_is_escaped_on_output(): void
    {
        $payload = '<script>alert(1)</script>';

        $inquiry = Inquiry::factory()->create([
            'name' => $payload,
            'email' => 'x@example.com',
            'phone' => $payload,
            'company' => $payload,
            'project_type' => $payload,
            'location' => $payload,
            'budget_range' => $payload,
            'heard_from' => $payload,
            'message' => 'A message containing '.$payload.' in the middle of it.',
        ]);

        foreach ([
            Livewire::actingAs($this->owner())->test(InquiryDetail::class, ['inquiry' => $inquiry])->html(),
            Livewire::actingAs($this->owner())->test(InquiryIndex::class)->html(),
        ] as $html) {
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html, 'the payload should render as characters');
        }
    }

    // ----------------------------------------------------------------- C2, C4

    public function test_opening_an_inquiry_marks_it_read_and_clears_the_badge(): void
    {
        $inquiry = Inquiry::factory()->create();

        $this->assertSame(1, Inquiry::query()->unread()->count());

        Livewire::actingAs($this->editor())->test(InquiryDetail::class, ['inquiry' => $inquiry]);

        $inquiry->refresh();

        $this->assertSame(InquiryStatus::Read, $inquiry->status);
        $this->assertNotNull($inquiry->read_at);
        $this->assertSame(0, Inquiry::query()->unread()->count());
    }

    public function test_the_detail_shows_everything_submitted_but_never_an_address(): void
    {
        $inquiry = Inquiry::factory()->create([
            'name' => 'Frances Bell',
            'company' => 'Meridian Press',
            'project_type' => 'Editorial',
            'location' => 'Newcastle',
            'budget_range' => '£2,000–£5,000',
            'heard_from' => 'A previous client',
            'message' => 'Two days in the spring.',
            'ip_hash' => Inquiry::hashIp('203.0.113.42'),
        ]);

        $html = Livewire::actingAs($this->owner())
            ->test(InquiryDetail::class, ['inquiry' => $inquiry])
            ->html();

        foreach (['Frances Bell', 'Meridian Press', 'Editorial', 'Newcastle',
                  'A previous client', 'Two days in the spring.'] as $shown) {
            $this->assertStringContainsString(e($shown), $html, "{$shown} is not on the detail page");
        }

        // When it arrived, and where from.
        $this->assertStringContainsString('Arrived', $html);
        $this->assertStringContainsString('Contact Form', $html);
        $this->assertStringContainsString('Consent', $html);

        /*
         * Neither the address nor its hash. The address is not stored at all;
         * the hash is stored and deliberately not rendered, because a hash
         * displayed in a UI is one screenshot away from being a correlatable
         * identifier again.
         */
        $this->assertStringNotContainsString('203.0.113.42', $html);
        $this->assertStringNotContainsString((string) $inquiry->ip_hash, $html);
        $this->assertStringContainsString('cannot be shown', $html);
    }

    public function test_the_list_filters_by_status_and_searches(): void
    {
        Inquiry::factory()->create(['name' => 'Frances Bell', 'company' => 'Meridian Press']);
        Inquiry::factory()->status(InquiryStatus::Booked)->create(['name' => 'Iris Nolan']);
        Inquiry::factory()->status(InquiryStatus::Spam)->create(['name' => 'Cheap Watches']);

        $component = Livewire::actingAs($this->editor())->test(InquiryIndex::class);

        // The default view is open work, so spam is not in it.
        $component->assertSee('Frances Bell')->assertSee('Iris Nolan')->assertDontSee('Cheap Watches');

        $component->set('status', InquiryStatus::Booked->value)
            ->assertSee('Iris Nolan')->assertDontSee('Frances Bell');

        // Search stays inside the status filter rather than escaping it.
        $component->set('status', 'open')->set('search', 'Meridian')
            ->assertSee('Frances Bell')->assertDontSee('Iris Nolan');

        $component->set('search', 'Cheap')->assertDontSee('Cheap Watches');
    }

    public function test_the_sidebar_shows_an_unread_count(): void
    {
        Inquiry::factory()->count(3)->create();
        Inquiry::factory()->status(InquiryStatus::Booked)->create();

        $html = $this->actingAs($this->owner())
            ->get(route('legacy.inquiries.index'))
            ->assertOk()
            ->getContent();

        // Three unread, not four.
        $this->assertMatchesRegularExpression('/admin-sidebar__count[^>]*>\s*3\s*</', $html);
        $this->assertStringContainsString('3 unread', $html);
    }
}
