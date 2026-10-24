<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 38 G1 — the persistent contact link. Its geometry (44px, the trailing
 * corner, never covering the pager or the footer) is asserted at 390 in a
 * browser by tests/Support/pill-check.mjs; this asserts what the server sends.
 */
class ContactPillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(SettingsSeeder::class);
        Settings::flush();
    }

    public function test_it_carries_the_owners_own_invitation_on_ordinary_pages(): void
    {
        foreach (['/' => 'Request a quote', '/ar' => 'اطلب عرض سعر', '/about' => 'Request a quote', '/ar/about' => 'اطلب عرض سعر'] as $url => $label) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<a class="contact-pill" href="[^"]*/contact">'.preg_quote($label, '#').'</a>#u', $html, $url);
        }
    }

    /** Never on a contact page, in either language — it would point at itself. */
    public function test_it_is_absent_on_the_contact_pages(): void
    {
        foreach (['/contact', '/ar/contact'] as $url) {
            $this->assertStringNotContainsString('contact-pill', (string) $this->get($url)->assertOk()->getContent(), $url);
        }
    }

    /** The label is the setting, not copy in the layout. */
    public function test_the_label_is_a_setting(): void
    {
        Settings::set('closing.cta', 'Write to the studio', 'string', 'contact');
        Settings::flush();

        $this->assertStringContainsString('>Write to the studio</a>', (string) $this->get('/')->getContent());
    }
}
