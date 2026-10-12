<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * D3–D5 — EVERY BYTE FILAMENT RECEIVES GOES THROUGH StoreUploadedMedia.
 *
 * This is the one guarantee in the task that cannot be checked by reading the
 * code, because Filament's FileUpload stores to a disk BY DEFAULT. One missing
 * ->saveUploadedFileUsing() and the panel writes files itself: no content-based
 * type detection, no sha256 dedupe, no megapixel ceiling, no queued renditions.
 * Nothing would fail. The library would just start filling with files the rest
 * of the application does not know about.
 *
 * So these tests drive the REAL Livewire component through the REAL action
 * modal with REAL image bytes, and assert the four guarantees on the far side.
 * Nothing here calls StoreUploadedMedia directly — if it did, it would be
 * testing the action rather than the panel's use of it.
 * ───────────────────────────────────────────────────────────────────────────
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    /** Drive the panel's own upload modal with one file. */
    private function uploadThroughThePanel(UploadedFile $file): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ListMedia::class)
            ->mountAction('upload')
            ->set('mountedActions.0.data.files', [$file])
            ->call('callMountedAction');
    }

    // ----------------------------------------------------------------- D3

    /** A real photograph arrives as a Media row, queued, with its checksum. */
    public function test_an_upload_goes_through_the_pipeline(): void
    {
        Queue::fake();

        $this->uploadThroughThePanel(UploadedFile::fake()->image('Holiday Photo.JPG', 1200, 800));

        $media = Media::query()->sole();

        // 1. The record exists and carries the pipeline's own fields.
        $this->assertSame('Holiday Photo.JPG', $media->original_filename);
        $this->assertSame(MediaStatus::Pending, $media->status);
        $this->assertSame(1200, $media->width);
        $this->assertSame(800, $media->height);
        $this->assertSame('media_originals', $media->disk);
        $this->assertSame(auth()->id(), $media->uploaded_by);

        // 2. A sha256 over the bytes, which only StoreUploadedMedia computes.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $media->checksum);

        // 3. The original is on the PRIVATE disk, at the pipeline's own path.
        Storage::disk('media_originals')->assertExists($media->path);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $media->path);

        // 4. The expensive work was handed to the queue, not done inline.
        Queue::assertPushed(ProcessMediaVariants::class);

        /*
         * AND FILAMENT WROTE NOTHING OF ITS OWN. saveUploadedFileUsing returns
         * null, so the form stores no path; the only file on disk is the one
         * the action put there.
         */
        $this->assertCount(
            1,
            Storage::disk('media_originals')->allFiles(),
            'something other than StoreUploadedMedia wrote to the originals disk'
        );
    }

    /** The same bytes twice is one row and one file. */
    public function test_identical_bytes_are_stored_once(): void
    {
        Queue::fake();

        $first = UploadedFile::fake()->image('first.jpg', 640, 480);
        $bytes = (string) file_get_contents($first->getRealPath());

        $this->uploadThroughThePanel($first);

        $media = Media::query()->sole();
        $path = (string) tempnam(sys_get_temp_dir(), 'dedupe-');
        file_put_contents($path, $bytes);

        // A different NAME, identical bytes — the dedupe is on content.
        $this->uploadThroughThePanel(
            new \Illuminate\Http\Testing\File('renamed-copy.jpg', fopen($path, 'rb'))
        );

        $this->assertSame(1, Media::query()->count(), 'the sha256 dedupe did not hold');
        $this->assertCount(1, Storage::disk('media_originals')->allFiles());
        $this->assertSame('first.jpg', $media->refresh()->original_filename);

        unlink($path);
    }

    /** A .jpg full of PHP never reaches the disk. */
    public function test_a_disguised_php_file_is_refused(): void
    {
        Queue::fake();

        $this->uploadThroughThePanel(
            UploadedFile::fake()->createWithContent(
                'innocent.jpg',
                "<?php @eval(\$_GET['x']); ?>\xFF\xD8\xFF\xE0 not really a jpeg"
            )
        );

        $this->assertSame(0, Media::query()->count(), 'a non-image was recorded');
        $this->assertCount(
            0,
            Storage::disk('media_originals')->allFiles(),
            'A DISGUISED PHP FILE REACHED THE DISK'
        );
        Queue::assertNothingPushed();
    }

    /** A GIF is a real image and is still refused: the accept list is the bytes'. */
    public function test_an_unsupported_image_type_is_refused(): void
    {
        Queue::fake();

        $gd = imagecreatetruecolor(50, 50);
        ob_start();
        imagegif($gd);
        $gif = (string) ob_get_clean();

        // Named .jpg and claimed as JPEG. getimagesize() says otherwise.
        $this->uploadThroughThePanel(
            UploadedFile::fake()->createWithContent('photo.jpg', $gif)
        );

        $this->assertSame(0, Media::query()->count());
        $this->assertCount(0, Storage::disk('media_originals')->allFiles());
    }

    // ----------------------------------------------------------------- D5

    /**
     * The decompression-bomb guard, through the panel.
     *
     * 50 megapixels is the configured ceiling. A 9000×6000 frame is 54, which
     * is a plausible medium-format file rather than a contrived one — the
     * guard has to refuse it without first decoding it into memory.
     */
    public function test_the_megapixel_ceiling_holds(): void
    {
        Queue::fake();

        $this->assertSame(50, (int) config('media.max_megapixels'));

        $this->uploadThroughThePanel(UploadedFile::fake()->image('enormous.jpg', 9000, 6000));

        $this->assertSame(0, Media::query()->count(), 'a 54 megapixel image was accepted');
        $this->assertCount(0, Storage::disk('media_originals')->allFiles());

        // Just under the ceiling goes through, so the guard is a ceiling and
        // not a blanket refusal of large files.
        $this->uploadThroughThePanel(UploadedFile::fake()->image('large.jpg', 8000, 6000));

        $this->assertSame(1, Media::query()->count(), '48 megapixels should be accepted');
    }

    // ----------------------------------------------------------------- D4

    /** An image a story still shows cannot be deleted, and the refusal counts. */
    public function test_media_in_use_cannot_be_deleted_and_the_refusal_names_the_count(): void
    {
        $media = Media::factory()->create();
        $project = Project::factory()->create();

        foreach (['cover', 'hero', 'gallery'] as $i => $role) {
            MediaUsage::query()->create([
                'media_id' => $media->getKey(),
                'usable_type' => $project->getMorphClass(),
                'usable_id' => $project->getKey(),
                'role' => $role,
                'sort_order' => $i,
            ]);
        }

        $response = Gate::inspect('delete', $media);

        $this->assertTrue($response->denied());
        $this->assertStringContainsString('3 places', (string) $response->message());

        /*
         * AND THE EDITOR IS TOLD. The button stays on screen rather than
         * vanishing — Filament's default for a denied action is to hide it,
         * which would have made the count unreadable by anybody. Clicking it
         * surfaces the policy's sentence and the record survives.
         */
        Livewire::test(ListMedia::class)
            ->assertActionVisible(TestAction::make('delete')->table($media))
            ->callAction(TestAction::make('delete')->table($media))
            ->assertNotified((string) $response->message());

        $this->assertDatabaseHas('media', ['id' => $media->getKey(), 'deleted_at' => null]);
    }

    /** With nothing pointing at it, the same image deletes. */
    public function test_unused_media_deletes(): void
    {
        $media = Media::factory()->create();

        $this->assertTrue(Gate::inspect('delete', $media)->allowed());

        Livewire::test(ListMedia::class)
            ->callAction(TestAction::make('delete')->table($media));

        $this->assertSoftDeleted('media', ['id' => $media->getKey()]);
    }

    /**
     * BULK DELETE OF MEDIA IS REFUSED, AND THAT IS THE DESIGN.
     *
     * deleteAny is asked without a record, so the per-image usage rule cannot
     * be evaluated — and a bulk action that silently skipped the check is how
     * a published page ends up with a missing frame.
     */
    public function test_there_is_no_bulk_delete_for_media(): void
    {
        Media::factory()->create();

        $names = [];

        foreach ($this->table()->getToolbarActions() as $action) {
            foreach ($action instanceof \Filament\Actions\ActionGroup ? $action->getActions() : [$action] as $inner) {
                $names[] = $inner->getName();
            }
        }

        $this->assertNotEmpty($names, 'the toolbar has no actions at all, so this proves nothing');
        $this->assertNotContains('delete', $names, 'media has a bulk delete');

        // Restore and force-delete do not have the per-record problem.
        $this->assertContains('restore', $names);
        $this->assertContains('forceDelete', $names);

        // And the policy is where the refusal lives, with its reason.
        $response = Gate::inspect('deleteAny', Media::class);

        $this->assertTrue($response->denied());
        $this->assertStringContainsString('one image at a time', (string) $response->message());
    }

    // ----------------------------------------------------------------- B1

    /**
     * THE CONDITIONAL POLL.
     *
     * A list that polls for ever is a list that wakes the server every three
     * seconds for ever. This one polls only while something is actually
     * pending or processing, and goes quiet when the queue has caught up.
     */
    public function test_the_list_polls_only_while_the_queue_is_busy(): void
    {
        $this->assertNull(
            $this->pollInterval(),
            'the media list polls with an idle queue'
        );

        Media::factory()->create(['status' => MediaStatus::Processing]);

        $this->assertSame('3s', $this->pollInterval());

        Media::query()->update(['status' => MediaStatus::Ready]);

        $this->assertNull($this->pollInterval());
    }

    private function pollInterval(): ?string
    {
        return $this->table()->getPollingInterval();
    }

    private function table(): \Filament\Tables\Table
    {
        return MediaResource::table(
            \Filament\Tables\Table::make(Livewire::test(ListMedia::class)->instance())
        );
    }
}
