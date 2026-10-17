<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\InquiryAcknowledged;
use App\Models\Inquiry;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 34e B — THE NAME ON THE ENVELOPE.
 *
 * The mail BODY has carried the studio's name since the vendor layout was
 * published: the header, the title, the footer, and the Laravel logo removed
 * from the top of it. The FROM LINE did not. config/mail.php reads
 * MAIL_FROM_NAME, which falls back to APP_NAME, which on this installation is
 * literally "Laravel" — so a prospective client's acknowledgement arrived
 * from a PHP framework, and every surface inside the message was right.
 *
 * Asserted by SENDING a message and reading the header off the transport,
 * not by reading config back: config is what was wrong, and a test that reads
 * it proves only that the same wrong value is still there.
 * ───────────────────────────────────────────────────────────────────────────
 */
class MailSenderTest extends TestCase
{
    use RefreshDatabase;

    /** The From header of a message this application actually built. */
    private function fromHeaderOf(object $mailable): string
    {
        Mail::to('client@example.test')->send($mailable);

        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'nothing was sent');

        return $messages[count($messages) - 1]
            ->getOriginalMessage()
            ->getHeaders()
            ->get('From')
            ->getBodyAsString();
    }

    public function test_the_sender_is_the_studio_not_the_framework(): void
    {
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
        Settings::overlayConfig();

        $from = $this->fromHeaderOf(new InquiryAcknowledged(Inquiry::factory()->create()));

        $this->assertStringContainsString('Anas Fareed', $from);
        $this->assertStringNotContainsString('Laravel', $from);
    }

    /** It follows a rename, like every other surface. */
    public function test_the_sender_follows_the_setting(): void
    {
        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $setting = Setting::query()->where('key', 'site.name')->firstOrFail();
        $setting->setTranslations('value', ['en' => 'Zzyzx Quorum', 'ar' => 'زيزكس']);
        $setting->save();

        Settings::flush();
        Settings::overlayConfig();

        $from = $this->fromHeaderOf(new InquiryAcknowledged(Inquiry::factory()->create()));

        $this->assertStringContainsString('Zzyzx Quorum', $from);
    }

    /**
     * And in Arabic it is the Arabic name.
     *
     * The header is RFC 2047 encoded for non-ASCII, so the raw bytes are
     * base64 — decoded here rather than asserted around, because what the
     * recipient sees is the decoded form.
     */
    public function test_the_sender_is_translated(): void
    {
        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $this->app->setLocale('ar');
        Settings::flush();
        Settings::overlayConfig();

        $from = $this->fromHeaderOf(new InquiryAcknowledged(Inquiry::factory()->create()));

        $decoded = (string) iconv_mime_decode($from, 0, 'UTF-8');

        $this->assertStringContainsString('أنس فريد', $decoded, "raw header: {$from}");
    }
}
