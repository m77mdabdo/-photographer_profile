<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function admin(UserRole $role = UserRole::Editor): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    private function jpegBytes(int $width = 1200, int $height = 800): string
    {
        $gd = imagecreatetruecolor($width, $height);

        for ($i = 0; $i < 8; $i++) {
            imagefilledrectangle(
                $gd,
                (int) ($width * $i / 8), 0,
                (int) ($width * ($i + 1) / 8), $height,
                imagecolorallocate($gd, 30 * $i, 120, 200 - 20 * $i)
            );
        }

        ob_start();
        imagejpeg($gd, null, 85);
        $bytes = (string) ob_get_clean();
        unset($gd);

        return $bytes;
    }

    private function uploadFrom(string $bytes, string $name = 'frame.jpg'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'medialib-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function readyMedia(array $attributes = []): Media
    {
        $media = Media::factory()->create(array_merge([
            'status' => MediaStatus::Ready,
            'width' => 1200,
            'height' => 800,
        ], $attributes));

        $path = sprintf('2026/09/%s/768.webp', $media->uuid);
        Storage::disk('media_public')->put($path, 'x');

        $media->variants()->create([
            'format' => 'webp', 'width' => 768, 'height' => 512,
            'disk' => 'media_public', 'path' => $path, 'size_bytes' => 1024,
        ]);

        return $media->refresh()->load('variants');
    }

    // ----------------------------------------------------------------- 1

    public function test_a_guest_cannot_reach_any_admin_media_route(): void
    {
        $media = $this->readyMedia();

        $this->get(route('admin.media.index'))->assertRedirect(route('login'));
        $this->get(route('admin.media.show', $media))->assertRedirect(route('login'));
        $this->post(route('admin.media.store'))->assertRedirect(route('login'));
        $this->patch(route('admin.media.update', $media))->assertRedirect(route('login'));
        $this->delete(route('admin.media.destroy', $media))->assertRedirect(route('login'));
        $this->post(route('admin.media.reprocess', $media))->assertRedirect(route('login'));

        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------------- 2

    public function test_an_admin_can_upload_and_a_job_is_queued(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin())
            ->post(route('admin.media.store'), [
                'files' => [$this->uploadFrom($this->jpegBytes())],
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['media' => [['id', 'uuid', 'name', 'status', 'url']]]);

        $this->assertSame(1, Media::count());

        $media = Media::sole();
        $this->assertSame(MediaStatus::Pending, $media->status);
        Storage::disk('media_originals')->assertExists($media->path);

        Queue::assertPushed(ProcessMediaVariants::class, 1);
    }

    // ----------------------------------------------------------------- 3

    public function test_a_disguised_php_file_is_a_422_not_a_500(): void
    {
        Queue::fake();

        $path = (string) tempnam(sys_get_temp_dir(), 'evil-');
        file_put_contents($path, '<?php echo 1;');
        $this->temporaryFiles[] = $path;

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.media.store'), [
                'files' => [new UploadedFile($path, 'payload.jpg', 'image/jpeg', null, true)],
            ]);

        $response->assertStatus(422);

        $this->assertSame(0, Media::count());
        $this->assertCount(0, Storage::disk('media_originals')->allFiles());
        Queue::assertNothingPushed();
    }

    // ----------------------------------------------------------------- 4

    public function test_an_oversized_file_is_rejected_by_validation(): void
    {
        Queue::fake();

        // 70 MB against a 60 MB ceiling. Faked so no real 70 MB file is written.
        $file = UploadedFile::fake()->create('huge.jpg', 70 * 1024, 'image/jpeg');

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.media.store'), ['files' => [$file]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('files.0');

        $this->assertSame(0, Media::count());
        Queue::assertNothingPushed();
    }

    // ----------------------------------------------------------------- 5

    public function test_metadata_updates_persist_and_are_escaped_on_output(): void
    {
        $media = $this->readyMedia();
        $payload = '"><script>alert(1)</script>';

        $this->actingAs($this->admin())
            ->patch(route('admin.media.update', $media), [
                'alt' => $payload,
                'caption' => 'A caption',
                'credit' => 'Someone',
                'copyright' => '© 2026',
                'focal_x' => 0.25,
                'focal_y' => 0.75,
            ])
            ->assertRedirect();

        $media->refresh();

        // Stored verbatim — escaping is an output concern, not a storage one.
        $this->assertSame($payload, $media->alt);
        $this->assertSame(0.25, $media->focal_x);
        $this->assertSame(0.75, $media->focal_y);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.media.show', $media))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ----------------------------------------------------------------- 6

    public function test_deleting_media_with_usages_is_refused(): void
    {
        $media = $this->readyMedia();
        $user = $this->admin();

        $media->usages()->create([
            'usable_type' => User::class,
            'usable_id' => $user->id,
            'role' => 'portrait',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.media.destroy', $media))
            ->assertForbidden();

        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------------- 7

    public function test_deleting_unused_media_soft_deletes_and_keeps_the_files(): void
    {
        $media = $this->readyMedia();
        $variantPath = $media->variants->first()->path;

        $this->actingAs($this->admin())
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect(route('admin.media.index'));

        $this->assertSoftDeleted('media', ['id' => $media->id]);

        // Soft delete is reversible precisely because the bytes survive.
        Storage::disk('media_public')->assertExists($variantPath);
        $this->assertDatabaseHas('media_variants', ['id' => $media->variants->first()->id]);
    }

    // ----------------------------------------------------------------- 8

    public function test_only_an_owner_may_force_delete(): void
    {
        $media = $this->readyMedia();

        $this->assertFalse($this->admin(UserRole::Editor)->can('forceDelete', $media));
        $this->assertTrue($this->admin(UserRole::Owner)->can('forceDelete', $media));
    }

    // ----------------------------------------------------------------- 9

    public function test_the_index_paginates_and_search_narrows_the_results(): void
    {
        Media::factory()->count(30)->create(['status' => MediaStatus::Ready]);
        $needle = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'original_filename' => 'dune-at-dawn.jpg',
        ]);

        $user = $this->admin();

        $first = $this->actingAs($user)->get(route('admin.media.index'));
        $first->assertOk();
        $this->assertCount(24, $first->viewData('media')->items(), 'page one holds 24');
        $this->assertSame(31, $first->viewData('media')->total());

        $second = $this->actingAs($user)->get(route('admin.media.index', ['page' => 2]));
        $this->assertCount(7, $second->viewData('media')->items(), 'page two holds the rest');

        $filtered = $this->actingAs($user)->get(route('admin.media.index', ['q' => 'dune-at-dawn']));
        $filtered->assertOk();
        $this->assertSame(1, $filtered->viewData('media')->total());
        $this->assertSame($needle->id, $filtered->viewData('media')->items()[0]->id);

        $byStatus = $this->actingAs($user)->get(route('admin.media.index', ['status' => 'failed']));
        $this->assertSame(0, $byStatus->viewData('media')->total());
    }

    // ----------------------------------------------------------------- 10

    public function test_reprocess_redispatches_the_job(): void
    {
        Queue::fake();

        $media = $this->readyMedia();

        $this->actingAs($this->admin())
            ->post(route('admin.media.reprocess', $media))
            ->assertRedirect();

        Queue::assertPushed(
            ProcessMediaVariants::class,
            fn (ProcessMediaVariants $job): bool => $job->media->is($media)
        );

        // Reset to pending so the grid shows it as unfinished again.
        $this->assertSame(MediaStatus::Pending, $media->refresh()->status);
    }
}
