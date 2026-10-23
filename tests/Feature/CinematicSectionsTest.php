<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\CinematicSections\Pages\CreateCinematicSection;
use App\Filament\Resources\CinematicSections\Pages\EditCinematicSection;
use App\Models\CinematicSection;
use App\Models\Media;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cinematic sections (lighting brief, 22 Oct 2026): the owner's controls in
 * the panel and what the home page renders from them. The light itself —
 * forward, reverse, direction on screen, reduced motion — is measured in a
 * browser by tests/Support/cinematic-check.mjs.
 */
class CinematicSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_public', config('filesystems.disks.media_public'));
        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role, 'two_factor_confirmed_at' => now()]);
    }

    private function ready(): Media
    {
        return Media::factory()->create(['status' => MediaStatus::Ready]);
    }

    // ------------------------------------------------------------- the panel

    public function test_an_editor_creates_a_gallery_of_four_in_both_languages(): void
    {
        $this->actingAs($this->user(UserRole::Editor));
        $frames = collect(range(1, 4))->map(fn () => $this->ready());

        Livewire::test(CreateCinematicSection::class)
            ->fillForm([
                'type' => 'gallery',
                'is_active' => true,
                'title' => ['en' => 'Light, placed with intent.', 'ar' => 'ضوءٌ يوضع بقصد.'],
                'items' => $frames->mapWithKeys(fn (Media $m, int $i) => ['r'.$i => ['media_id' => $m->id]])->all(),
                'light_direction' => 'rtl',
                'intensity' => 'strong',
                'darkness' => 'night',
                'pace' => 'quick',
                'overlay' => 'warm',
                'mobile_layout' => 'stack',
                'animate' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $section = CinematicSection::query()->with('items')->sole();

        $this->assertSame('gallery', $section->type);
        $this->assertSame('ضوءٌ يوضع بقصد.', $section->getTranslation('title', 'ar', false));
        $this->assertSame($frames->pluck('id')->all(), $section->items->pluck('media_id')->all(), 'the photographs lost their order');
        // Spotlight on by default: "Night" is the brief's 98% for the others.
        $this->assertSame(['direction' => 'rtl', 'mode' => 'spotlight', 'intensity' => '0.8', 'darkness' => '0.98', 'pace' => '0.5', 'softness' => '0.7', 'overlay' => 'warm', 'mobile' => 'stack', 'mobileWidth' => '80', 'animate' => '1'], $section->lighting());

        // Every photograph is claimed, so the library refuses to delete it.
        $this->assertSame(4, $section->mediaUsages()->where('role', 'gallery')->count());
        $this->assertFalse($this->user(UserRole::Owner)->can('delete', $frames->first()));
    }

    public function test_a_gallery_needs_four_to_five_photographs(): void
    {
        $this->actingAs($this->user(UserRole::Owner));

        foreach ([3, 6] as $count) {
            Livewire::test(CreateCinematicSection::class)
                ->fillForm([
                    'type' => 'gallery',
                    'items' => collect(range(1, $count))->mapWithKeys(fn (int $i) => ['r'.$i => ['media_id' => $this->ready()->id]])->all(),
                ])
                ->call('create')
                ->assertHasFormErrors(['items']);
        }

        $this->assertSame(0, CinematicSection::query()->count());
    }

    public function test_a_video_must_be_mp4_or_webm(): void
    {
        $this->actingAs($this->user(UserRole::Owner));

        Livewire::test(CreateCinematicSection::class)
            ->fillForm([
                'type' => 'video',
                'video_path' => UploadedFile::fake()->create('not-a-video.pdf', 200, 'application/pdf'),
                'poster_media_id' => $this->ready()->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['video_path']);

        $this->assertSame(0, CinematicSection::query()->count());
    }

    public function test_reordering_the_photographs_is_saved(): void
    {
        $this->actingAs($this->user(UserRole::Owner));
        $section = CinematicSection::factory()->gallery(4)->create();
        $ids = $section->items()->pluck('media_id')->all();

        $item = $section->items()->get();
        // Reverse the order the way the repeater saves it.
        foreach ($item as $i => $row) {
            $row->update(['sort_order' => 3 - $i]);
        }

        $this->assertSame(array_reverse($ids), $section->fresh()->items->pluck('media_id')->all());
    }

    public function test_editing_and_hiding_and_deleting_and_restoring(): void
    {
        $this->actingAs($this->user(UserRole::Editor));
        $section = CinematicSection::factory()->create(['title' => ['en' => 'Before', 'ar' => 'قبل']]);

        Livewire::test(EditCinematicSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['title' => ['en' => 'After', 'ar' => 'بعد'], 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('After', $section->getTranslation('title', 'en', false));
        $this->assertFalse($section->is_active);

        $section->delete();
        $this->assertSame(0, $section->mediaUsages()->count(), 'a binned section still holds its photographs');
        $section->restore();
        $this->assertSame(1, $section->mediaUsages()->count(), 'restoring did not claim the photograph back');
    }

    public function test_who_may_do_what(): void
    {
        $section = CinematicSection::factory()->create();
        $owner = $this->user(UserRole::Owner);
        $editor = $this->user(UserRole::Editor);

        $this->assertTrue($editor->can('update', $section));
        $this->assertTrue($editor->can('delete', $section));
        $this->assertFalse($editor->can('forceDelete', $section), 'an Editor may not delete permanently');
        $this->assertTrue($owner->can('forceDelete', $section));

        // A signed-out visitor never reaches the panel.
        $this->get('/admin/cinematic-sections')->assertRedirect();
    }

    // ------------------------------------------------------------- the page

    public function test_the_home_page_renders_the_sequence_in_order_and_fully_lit(): void
    {
        $gallery = CinematicSection::factory()->gallery(5)->create(['sort_order' => 2, 'light_direction' => 'rtl', 'title' => ['en' => 'Second', 'ar' => 'الثاني']]);
        $image = CinematicSection::factory()->create(['sort_order' => 1, 'title' => ['en' => 'First', 'ar' => 'الأول']]);
        CinematicSection::factory()->create(['sort_order' => 3, 'is_active' => false, 'title' => ['en' => 'Hidden one', 'ar' => 'مخفي']]);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Second'), strpos($html, 'First'), 'the sections are out of order');
        $this->assertStringNotContainsString('Hidden one', $html);
        $this->assertSame(5, substr_count($html, 'data-cine-panel'));
        $this->assertStringContainsString('data-direction="rtl"', $html);

        // Fully lit from the server: no veil, no light, no dark style.
        $this->assertStringNotContainsString('cine__veil', $html);
        $this->assertStringNotContainsString('cine__light', $html);
        $this->assertDoesNotMatchRegularExpression('/class="cine[^"]*"[^>]*style="[^"]*opacity/', $html);

        // And Task 41's tiled dissolve is kept off them.
        $this->assertStringContainsString('class="cinematic" data-register="fixed" data-arrive-skip', $html);

        // Arabic.
        $this->get('/ar')->assertOk()->assertSee('الثاني')->assertSee('الأول')->assertDontSee('مخفي');
    }

    public function test_a_section_with_nothing_to_show_is_not_rendered(): void
    {
        // A gallery of three ready photographs and one still processing.
        $short = CinematicSection::factory()->gallery(3)->create(['title' => ['en' => 'Too few', 'ar' => 'قليل']]);
        $short->items()->create(['media_id' => Media::factory()->create(['status' => MediaStatus::Processing])->id, 'sort_order' => 9]);

        // A video whose file is not there, and one whose file is empty.
        CinematicSection::factory()->video('cinematic/missing.mp4')->create(['title' => ['en' => 'No file', 'ar' => 'بلا ملف']]);
        Storage::disk('media_public')->put('cinematic/empty.mp4', '');
        CinematicSection::factory()->video('cinematic/empty.mp4')->create(['title' => ['en' => 'Empty file', 'ar' => 'ملف فارغ']]);

        // A path that tries to leave the disk.
        CinematicSection::factory()->video('../../.env')->create(['title' => ['en' => 'Escape', 'ar' => 'هروب']]);

        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach (['Too few', 'No file', 'Empty file', 'Escape'] as $title) {
            $this->assertStringNotContainsString($title, $html);
        }
        $this->assertStringNotContainsString('class="cinematic"', $html);
    }

    public function test_a_video_renders_muted_inline_with_a_poster_and_no_preload(): void
    {
        Storage::disk('media_public')->put('cinematic/clip.mp4', str_repeat('x', 2048));
        $poster = $this->ready();
        $poster->variants()->create([
            'format' => 'webp', 'width' => 1920, 'height' => 1080, 'disk' => 'media_public',
            'path' => "2026/09/{$poster->uuid}/1920.webp", 'size_bytes' => 4242,
        ]);
        CinematicSection::factory()->video('cinematic/clip.mp4', $poster->id)->create();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<video class="cine__media"\s+muted loop playsinline controls\s+preload="none"#', $html);
        $this->assertStringContainsString('<source src="/storage/media/cinematic/clip.mp4" type="video/mp4">', $html);
        $this->assertStringNotContainsString('autoplay', $html, 'autoplay is the script\'s decision, only while on screen');

        // A poster attribute downloads at page load whatever preload says; it
        // raced the hero and doubled LCP. It is handed over near the screen.
        $this->assertDoesNotMatchRegularExpression('#<video[^>]*\sposter=#', $html);
        $this->assertMatchesRegularExpression('#<video[^>]*\sdata-poster="[^"]*/1920\.webp"#', $html);
    }

    public function test_direction_follows_the_language_unless_overridden(): void
    {
        CinematicSection::factory()->gallery(4)->create(['light_direction' => 'auto', 'title' => ['en' => 'Auto', 'ar' => 'تلقائي']]);

        $this->get('/')->assertSee('<ol class="cine__strip" role="list" dir="ltr"', false);
        $this->get('/ar')->assertSee('<ol class="cine__strip" role="list" dir="rtl"', false);

        CinematicSection::query()->update(['light_direction' => 'ltr']);
        $this->get('/ar')->assertSee('<ol class="cine__strip" role="list" dir="ltr"', false)->assertSee('data-mode="spotlight"', false);
    }

    public function test_spotlight_off_is_a_sweep_with_its_own_darkness(): void
    {
        $section = CinematicSection::factory()->gallery(4)->create(['spotlight' => false, 'darkness' => 'deep']);

        $this->assertSame('sweep', $section->lighting()['mode']);
        $this->assertSame('0.62', $section->lighting()['darkness']);
        $this->get('/')->assertSee('data-mode="sweep"', false);
    }

    public function test_animation_off_is_carried_to_the_page(): void
    {
        CinematicSection::factory()->gallery(4)->create(['animate' => false]);

        $this->get('/')->assertOk()->assertSee('data-animate="0"', false);
    }

    public function test_the_home_page_query_count_does_not_grow_with_sections(): void
    {
        // One of each kind first: eager loading costs a query per KIND
        // present (featured images load only when there is one), which is
        // fixed; what must not happen is a query per SECTION.
        CinematicSection::factory()->gallery(4)->create();
        CinematicSection::factory()->create();
        $this->get('/');

        $count = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$count): void { $count++; });
        $this->get('/')->assertOk();
        $one = $count;

        CinematicSection::factory()->gallery(5)->create();
        CinematicSection::factory()->create();
        $count = 0;
        $this->get('/')->assertOk();

        $this->assertSame($one, $count, 'each cinematic section costs the home page queries');
    }
}
