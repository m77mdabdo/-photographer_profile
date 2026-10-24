<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Testimonials\Pages\ManageTestimonials;
use App\Models\Media;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\Locale;
use App\Support\SiteCopy;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D2 — WHAT CLIENTS SAID.
 *
 * Surfaced immediately before the closing invitation, which is the last thing
 * a visitor reads before being asked to get in touch.
 *
 * The guarantees worth holding are about restraint: a draft quote must not
 * appear, a studio with none must show no section at all rather than a
 * heading over nothing, and the strip must not cost a query per quote on the
 * one page everybody loads.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class TestimonialsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    // ----------------------------------------------------------- the public

    /** A published quote appears, with who said it and what they do. */
    public function test_a_published_testimonial_appears_on_the_home_page(): void
    {
        Testimonial::factory()->published()->create([
            'quote' => ['en' => 'They photographed the day we actually had.', 'ar' => 'صوّروا اليوم كما كان.'],
            'attribution' => ['en' => 'Imogen Hartley', 'ar' => 'إيموجين هارتلي'],
            'role' => ['en' => 'Hartley Press', 'ar' => 'دار هارتلي'],
        ]);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('They photographed the day we actually had.', $html);
        $this->assertStringContainsString('Imogen Hartley', $html);
        $this->assertStringContainsString('Hartley Press', $html);
    }

    /** A draft one does not, and neither does one dated for next week. */
    public function test_an_unpublished_testimonial_is_not_shown(): void
    {
        Testimonial::factory()->create([
            'quote' => ['en' => 'A draft nobody approved.', 'ar' => 'مسوّدة.'],
            'status' => PublishStatus::Draft,
        ]);

        Testimonial::factory()->create([
            'quote' => ['en' => 'Scheduled for next week.', 'ar' => 'مجدولة.'],
            'status' => PublishStatus::Published,
            'published_at' => now()->addWeek(),
        ]);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('A draft nobody approved.', $html);
        $this->assertStringNotContainsString('Scheduled for next week.', $html);
    }

    /**
     * NO TESTIMONIALS MEANS NO SECTION.
     *
     * Not a heading over nothing, and not a placeholder quote. There is no
     * demo seeder for this table for the same reason: a stock photograph
     * standing in for a portfolio is one thing, and a fabricated sentence
     * attributed to a client is another.
     */
    public function test_a_studio_with_no_testimonials_shows_no_section(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('voices__title', $html);
        $this->assertStringNotContainsString(SiteCopy::get('testimonials.heading'), $html);
    }

    /** It renders in Arabic, in Arabic. */
    public function test_the_strip_renders_in_arabic(): void
    {
        Testimonial::factory()->published()->create([
            'quote' => ['en' => 'An English sentence.', 'ar' => 'صوّروا اليوم كما كان حقاً.'],
            'attribution' => ['en' => 'Imogen Hartley', 'ar' => 'إيموجين هارتلي'],
        ]);

        $html = (string) $this->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get('/ar')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('صوّروا اليوم كما كان حقاً.', $html);
        $this->assertStringContainsString('إيموجين هارتلي', $html);
        $this->assertStringNotContainsString('An English sentence.', $html);
    }

    /** The order is the studio's, not the database's. */
    public function test_the_order_is_the_one_the_studio_chose(): void
    {
        Testimonial::factory()->published()->create([
            'quote' => ['en' => 'Second by choice.', 'ar' => 'ثانٍ.'],
            'sort_order' => 20,
        ]);

        Testimonial::factory()->published()->create([
            'quote' => ['en' => 'First by choice.', 'ar' => 'أوّل.'],
            'sort_order' => 10,
        ]);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Second by choice.'),
            strpos($html, 'First by choice.'),
            'the strip ignored its own order'
        );
    }

    /**
     * THE STRIP DOES NOT COST A QUERY PER QUOTE.
     *
     * It is on the home page, which is the one page everybody loads, and a
     * portrait is a media row with variants behind it.
     */
    public function test_the_strip_does_not_query_per_testimonial(): void
    {
        $portrait = Media::factory()->create(['status' => MediaStatus::Ready]);

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $this->get('/')->assertOk();

            $measured = $queries;
            DB::getEventDispatcher()->forget(QueryExecuted::class);

            return $measured;
        };

        Testimonial::factory()->published()->create(['media_id' => $portrait->id]);
        $this->get('/');            // warm every other cache first
        $one = $count();

        Testimonial::factory()->published()->count(3)->create(['media_id' => $portrait->id]);

        $this->assertSame($one, $count(), 'the testimonial strip queries per quote');
    }

    /** A quote survives its portrait being deleted. */
    public function test_deleting_a_portrait_does_not_delete_the_words(): void
    {
        $portrait = Media::factory()->create(['status' => MediaStatus::Ready]);

        $testimonial = Testimonial::factory()->published()->create([
            'quote' => ['en' => 'Words that outlive a picture.', 'ar' => 'كلمات.'],
            'media_id' => $portrait->id,
        ]);

        $portrait->forceDelete();

        $this->assertNotNull($testimonial->fresh(), 'the quote went with the portrait');
        $this->assertNull($testimonial->fresh()->media_id);

        $this->get('/')->assertOk()->assertSee('Words that outlive a picture.');
    }

    // ------------------------------------------------------------ the panel

    /** The resource lists them and creates one through the real form. */
    public function test_a_testimonial_is_written_in_both_locales_through_the_panel(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        Livewire::test(ManageTestimonials::class)
            ->callAction(TestAction::make('create')->table(), [
                'quote.en' => 'They photographed the day we actually had.',
                'quote.ar' => 'صوّروا اليوم كما كان.',
                'attribution.en' => 'Imogen Hartley',
                'attribution.ar' => 'إيموجين هارتلي',
                'role.en' => 'Hartley Press',
                'role.ar' => 'دار هارتلي',
                'status' => PublishStatus::Published->value,
                'published_at' => now()->subDay()->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoActionErrors();

        $testimonial = Testimonial::query()->sole();

        $this->assertSame('They photographed the day we actually had.', $testimonial->getTranslation('quote', 'en', fallback: false));
        $this->assertSame('صوّروا اليوم كما كان.', $testimonial->getTranslation('quote', 'ar', fallback: false));
        $this->assertSame('إيموجين هارتلي', $testimonial->getTranslation('attribution', 'ar', fallback: false));
    }

    /** Only an Owner may destroy one for good. */
    public function test_only_an_owner_can_permanently_delete_a_testimonial(): void
    {
        $testimonial = Testimonial::factory()->create();

        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->assertFalse($editor->can('forceDelete', $testimonial));
        $this->assertTrue($owner->can('forceDelete', $testimonial));
    }
}
