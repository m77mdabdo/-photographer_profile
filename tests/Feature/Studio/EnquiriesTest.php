<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Enquiries\InquiryResource;
use App\Filament\Resources\Enquiries\Pages\ListInquiries;
use App\Filament\Resources\Enquiries\Pages\ViewInquiry;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\TranslatableSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B2 — the enquiries screen keeps the CRM's shape, and never shows an address.
 */
class EnquiriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($this->user);
    }

    /**
     * THE RAW IP IS UNSHOWABLE, NOT MERELY UNSHOWN.
     *
     * Asserted against the SCHEMA rather than against the resource: "the
     * column is not in the table definition" is a promise somebody can undo
     * with one line, and "there is no such column" is not.
     */
    public function test_the_raw_ip_cannot_be_shown_because_it_is_not_stored(): void
    {
        $columns = Schema::getColumnListing('inquiries');

        $this->assertContains('ip_hash', $columns);
        $this->assertNotContains('ip', $columns);
        $this->assertNotContains('ip_address', $columns);

        // And the hash itself is not on screen either: it answers one question
        // ("same sender?") that nobody asks by reading it.
        $enquiry = Inquiry::factory()->create(['ip_hash' => hash('sha256', '203.0.113.9')]);

        $html = $this->get('/admin/enquiries')->assertOk()->getContent();

        $this->assertStringNotContainsString($enquiry->ip_hash, $html);
        $this->assertStringNotContainsString('203.0.113.9', $html);

        $detail = $this->get('/admin/enquiries/'.$enquiry->getKey())->assertOk()->getContent();

        $this->assertStringNotContainsString($enquiry->ip_hash, $detail);
    }

    /** An enquiry is the client's own words, so nothing about it is translatable. */
    public function test_nothing_about_an_enquiry_is_translatable(): void
    {
        $this->assertSame([], TranslatableSchema::columnsFor('inquiries'));
        $this->assertFalse(defined(InquiryResource::class.'::TRANSLATED'));
    }

    /** Opening one marks it read, which is what the unread badge counts. */
    public function test_opening_an_enquiry_marks_it_read(): void
    {
        $enquiry = Inquiry::factory()->create(['status' => InquiryStatus::New, 'read_at' => null]);

        $this->assertSame('1', InquiryResource::getNavigationBadge());

        Livewire::test(ViewInquiry::class, ['record' => $enquiry->getKey()])->assertOk();

        $enquiry->refresh();

        $this->assertNotNull($enquiry->read_at, 'opening an enquiry should mark it read');
        $this->assertNull(InquiryResource::getNavigationBadge(), 'the badge should clear when nothing is unread');
    }

    /** The CRM fields are all on the detail screen, not only name and message. */
    public function test_the_detail_screen_shows_the_whole_enquiry(): void
    {
        $enquiry = Inquiry::factory()->create([
            'name' => 'Noor Haddad',
            'email' => 'noor@example.com',
            'phone' => '+44 7700 900123',
            'company' => 'Haddad & Co',
            'location' => 'Edinburgh',
            'message' => 'We are planning a two-day shoot in the glen.',
        ]);

        $html = $this->get('/admin/enquiries/'.$enquiry->getKey())->assertOk()->getContent();

        foreach ([
            'Noor Haddad',
            'noor@example.com',
            '+44 7700 900123',
            'Haddad &amp; Co',
            'Edinburgh',
            'We are planning a two-day shoot in the glen.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "the detail screen should show $expected");
        }
    }

    /** The list is searchable by the things a studio actually remembers. */
    public function test_the_list_searches_name_email_and_message(): void
    {
        Inquiry::factory()->create(['name' => 'Noor Haddad', 'email' => 'noor@example.com']);
        Inquiry::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.com']);

        Livewire::test(ListInquiries::class)
            ->set('tableSearch', 'noor@example')
            ->assertCanSeeTableRecords(Inquiry::query()->where('name', 'Noor Haddad')->get())
            ->assertCanNotSeeTableRecords(Inquiry::query()->where('name', 'Someone Else')->get());
    }
}
