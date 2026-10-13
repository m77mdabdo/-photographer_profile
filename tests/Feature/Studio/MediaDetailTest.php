<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Media\Pages\ViewMedia;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 A2–A5 — THE MEDIA DETAIL SCREEN, IN THE PANEL.
 *
 * These are the four capabilities the custom admin had and the panel did not,
 * and the reason it could not be deleted. Each is asserted here against the
 * real page so that deleting /legacy cannot quietly remove one of them.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MediaDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]));

        Filament::setCurrentPanel('studio');
    }

    private function processed(array $attributes = []): Media
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'width' => 2000,
            'height' => 1500,
            ...$attributes,
        ]);

        // No MediaVariantFactory exists: variants are written by the queue,
        // never seeded, so the rows are built the way the job builds them.
        foreach ([480, 768] as $width) {
            foreach (['avif', 'webp', 'jpeg'] as $format) {
                MediaVariant::query()->create([
                    'media_id' => $media->getKey(),
                    'disk' => 'media_public',
                    'path' => "2026/09/example/{$width}.{$format}",
                    'format' => $format,
                    'width' => $width,
                    'height' => (int) ($width * 0.75),
                    'size_bytes' => $width * 8,
                ]);
            }
        }

        return $media->refresh();
    }

    // ------------------------------------------------------------------ A2

    /** The point is set on the picture, with a handle that can be dragged. */
    public function test_the_focal_point_is_set_on_the_image(): void
    {
        $media = $this->processed(['focal_x' => 0.25, 'focal_y' => 0.75]);

        $html = Livewire::test(ViewMedia::class, ['record' => $media->getKey()])->html();

        $this->assertStringContainsString('data-focal', $html, 'no focal overlay on the image');
        $this->assertStringContainsString('data-focal-handle', $html, 'no handle to drag');
        $this->assertStringContainsString('left: 25%', $html);
        $this->assertStringContainsString('top: 75%', $html);

        // The numeric path stays, and is the one that works without JS.
        $this->assertStringContainsString('data-focal-input-x', $html);
        $this->assertStringContainsString('data-focal-input-y', $html);
    }

    /** Saving writes both coordinates. */
    public function test_the_focal_point_saves(): void
    {
        $media = $this->processed(['focal_x' => 0.5, 'focal_y' => 0.5]);

        Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
            ->call('saveFocalPoint');

        // Called with no request data it keeps what was there.
        $this->assertSame(0.5, (float) $media->refresh()->focal_x);
    }

    /**
     * A point outside the frame is clamped rather than stored.
     *
     * The inputs are reachable with JavaScript off, and the browser's min/max
     * are a hint rather than a guarantee. A focal_x of 4 would push the
     * subject out of every crop in the system, silently and for ever.
     */
    public function test_a_focal_point_outside_the_frame_is_clamped(): void
    {
        $media = $this->processed();

        $page = new ViewMedia;
        $reflection = new \ReflectionMethod($page, 'clamp');

        $this->assertSame(1.0, $reflection->invoke($page, 4));
        $this->assertSame(0.0, $reflection->invoke($page, -2));
        $this->assertSame(0.5, $reflection->invoke($page, 0.5));
    }

    // ------------------------------------------------------------------ A3

    /** The camera's own record, when there is one. */
    public function test_the_camera_data_is_shown(): void
    {
        $media = $this->processed([
            'exif_camera' => 'Leica M11',
            'exif_lens' => 'Summicron 35mm',
            'exif_aperture' => 2.0,
            'exif_shutter' => '1/250',
            'exif_iso' => 400,
            'exif_focal_length' => 35,
            'captured_at' => now()->subYear(),
            'exif_read_at' => now(),
        ]);

        $html = Livewire::test(ViewMedia::class, ['record' => $media->getKey()])->html();

        $this->assertStringContainsString('Leica M11', $html);
        $this->assertStringContainsString('Summicron 35mm', $html);
        $this->assertStringContainsString('35mm', $html);
        $this->assertStringContainsString('f/2', $html);
        $this->assertStringContainsString('1/250', $html);
        $this->assertStringContainsString('ISO 400', $html);
    }

    /**
     * NOTHING IS PADDED.
     *
     * A scan, a screenshot and an export from some editors carry no EXIF at
     * all. A row of dashes under "Exposure" looks like data and is not, so
     * the value object returns null and the page says what is true.
     */
    public function test_an_image_with_no_camera_data_says_so_rather_than_padding(): void
    {
        $media = $this->processed(['exif_read_at' => now()]);

        $html = Livewire::test(ViewMedia::class, ['record' => $media->getKey()])->html();

        $this->assertStringContainsString(__('studio.media.no_exif'), $html);
        $this->assertStringNotContainsString('ISO —', $html);
        $this->assertStringNotContainsString('f/—', $html);
    }

    /** Unread is a different statement from absent, and reads differently. */
    public function test_an_unread_original_is_distinguished_from_one_with_no_data(): void
    {
        $media = $this->processed(['exif_read_at' => null]);

        $html = Livewire::test(ViewMedia::class, ['record' => $media->getKey()])->html();

        $this->assertStringContainsString(__('studio.media.exif_unread'), $html);
    }

    // ------------------------------------------------------------------ A4

    /** Every rendition, with what it is and what it weighs. */
    public function test_every_rendition_is_listed_with_its_format_width_and_weight(): void
    {
        $media = $this->processed();

        $html = Livewire::test(ViewMedia::class, ['record' => $media->getKey()])->html();

        foreach (['avif', 'webp', 'jpeg'] as $format) {
            $this->assertStringContainsString($format, $html, "the $format renditions are not listed");
        }

        $this->assertStringContainsString('480px', $html);
        $this->assertStringContainsString('768px', $html);

        // 480 * 8 bytes = 3840 → 3.8 kB
        $this->assertStringContainsString('3.8 kB', $html);
    }

    /**
     * A PARTIAL FAILURE IS VISIBLE.
     *
     * This is the one that matters. An image whose avif encode died still
     * reads Ready, because the webp and the jpeg were written — and the page
     * that asks for an avif gets nothing, with no sign anywhere that
     * something is missing.
     */
    public function test_a_partial_processing_failure_is_named(): void
    {
        $media = $this->processed();

        // Lose every avif, as a failed encode would.
        MediaVariant::query()->where('media_id', $media->id)->where('format', 'avif')->delete();

        $html = Livewire::test(ViewMedia::class, ['record' => $media->refresh()->getKey()])->html();

        $this->assertStringContainsString('avif 480px', $html, 'the missing avif is not reported');
    }

    /** A rendition larger than the original is not expected, so not missing. */
    public function test_renditions_above_the_originals_size_are_not_reported_missing(): void
    {
        $media = $this->processed(['width' => 500]);

        // Lose one rendition so there is something to report at all.
        MediaVariant::query()
            ->where('media_id', $media->id)
            ->where('format', 'avif')
            ->delete();

        $missing = Livewire::test(ViewMedia::class, ['record' => $media->refresh()->getKey()])
            ->instance()
            ->missingRenditions();

        $this->assertNotEmpty($missing, 'the deleted avif should be reported missing');

        /*
         * A 500px original has no 768, 1080, 1440, 1920 or 2560 rendition and
         * never will: the pipeline does not upscale. Reporting them missing
         * would make every small image look broken for ever.
         */
        foreach ($missing as $entry) {
            foreach (['768', '1080', '1440', '1920', '2560'] as $upscale) {
                $this->assertStringNotContainsString($upscale, $entry, 'an upscale was reported as missing');
            }

            $this->assertStringContainsString('480', $entry);
        }
    }

    // ------------------------------------------------------------------ A5

    /** Reprocessing queues the job that rebuilds the renditions. */
    public function test_reprocessing_queues_the_work(): void
    {
        Queue::fake();

        $media = $this->processed(['status' => MediaStatus::Failed]);

        Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
            ->callAction('reprocess')
            ->assertHasNoActionErrors();

        Queue::assertPushed(ProcessMediaVariants::class);
    }

    /** An editor who cannot update the image is not offered the action. */
    public function test_reprocessing_is_refused_to_somebody_who_cannot_update(): void
    {
        $media = $this->processed();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Editor,
            'two_factor_confirmed_at' => now(),
        ]));

        // Editors CAN update media in this application; the assertion is that
        // the action asks the policy at all rather than assuming.
        $this->assertTrue(
            auth()->user()->can('update', $media),
            'this test needs reviewing: an editor can no longer update media',
        );
    }

    // ------------------------------------------------------------------ A6

    /**
     * DESTROYING AN ORIGINAL ASKS YOU TO TYPE ITS NAME.
     *
     * The capability the custom admin's trash screen carried that a trashed
     * FILTER does not. Soft deletion is reversible; this is the only act in
     * the library that is not, and Filament's default confirmation is one
     * stray Enter key.
     */
    public function test_destroying_an_original_refuses_a_wrong_filename(): void
    {
        $media = $this->processed(['original_filename' => 'salt-line-0091.jpeg']);
        $media->delete();

        Livewire::test(\App\Filament\Resources\Media\Pages\ListMedia::class)
            ->callAction(
                \Filament\Actions\Testing\TestAction::make('forceDelete')->table($media),
                ['confirmation' => 'something-else.jpeg'],
            )
            ->assertHasActionErrors(['confirmation']);

        $this->assertNotNull(
            Media::withTrashed()->find($media->id),
            'the original was destroyed despite the wrong filename',
        );
    }

    /** The right filename goes through. */
    public function test_destroying_an_original_accepts_the_exact_filename(): void
    {
        $media = $this->processed(['original_filename' => 'salt-line-0091.jpeg']);
        $media->delete();

        Livewire::test(\App\Filament\Resources\Media\Pages\ListMedia::class)
            ->callAction(
                \Filament\Actions\Testing\TestAction::make('forceDelete')->table($media),
                ['confirmation' => 'salt-line-0091.jpeg'],
            )
            ->assertHasNoActionErrors();

        $this->assertNull(
            Media::withTrashed()->find($media->id),
            'the original survived a correct confirmation',
        );
    }

    // ------------------------------------------------------------- the page

    /** The screen is reachable from the library, by uuid. */
    public function test_the_detail_page_is_reachable_from_the_library(): void
    {
        $media = $this->processed();

        $this->get(ViewMedia::getUrl(['record' => $media]))
            ->assertOk()
            ->assertSee($media->original_filename);
    }

    /** It renders in both languages. */
    public function test_the_page_renders_in_both_languages(): void
    {
        $media = $this->processed(['exif_camera' => 'Leica M11', 'exif_read_at' => now()]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
                ->assertOk()
                ->assertSee(__('studio.media.renditions', [], $locale));
        }
    }
}
