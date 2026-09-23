<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Actions\Projects\SyncBlockMedia;
use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Integrity of block media, proven before any builder UI exists.
 */
class BlockMediaIntegrityTest extends TestCase
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
    }

    private function admin(UserRole $role = UserRole::Editor): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    private function media(): Media
    {
        $media = Media::factory()->create(['status' => MediaStatus::Ready, 'width' => 1200, 'height' => 800]);

        $path = sprintf('2026/09/%s/768.webp', $media->uuid);
        Storage::disk('media_public')->put($path, 'x');

        $media->variants()->create([
            'format' => 'webp', 'width' => 768, 'height' => 512,
            'disk' => 'media_public', 'path' => $path, 'size_bytes' => 1024,
        ]);

        return $media->refresh();
    }

    private function block(?Project $project = null, BlockType $type = BlockType::GalleryStrip): ProjectBlock
    {
        $project ??= Project::factory()->create();

        return $project->blocks()->create([
            'type' => $type,
            'sort_order' => 0,
            'data' => ['caption' => 'A caption'],
        ]);
    }

    // ----------------------------------------------------------------- 1

    public function test_syncing_creates_usage_rows_with_the_right_role_and_order(): void
    {
        $block = $this->block();
        $a = $this->media();
        $b = $this->media();
        $c = $this->media();

        app(SyncBlockMedia::class)($block, [$c->id, $a->id, $b->id]);

        $usages = $block->mediaUsages()->orderBy('sort_order')->get();

        $this->assertCount(3, $usages);
        $this->assertSame([$c->id, $a->id, $b->id], $usages->pluck('media_id')->all());
        $this->assertSame([0, 1, 2], $usages->pluck('sort_order')->all());

        foreach ($usages as $usage) {
            $this->assertSame('block', $usage->role);
            $this->assertSame(ProjectBlock::class, $usage->usable_type);
            $this->assertSame($block->id, $usage->usable_id);
        }
    }

    // ----------------------------------------------------------------- 2

    public function test_resyncing_removes_stale_rows_and_adds_new_ones(): void
    {
        $block = $this->block();
        $a = $this->media();
        $b = $this->media();
        $c = $this->media();

        app(SyncBlockMedia::class)($block, [$a->id, $b->id]);
        $this->assertSame(2, $block->mediaUsages()->count());

        app(SyncBlockMedia::class)($block, [$b->id, $c->id]);

        $usages = $block->mediaUsages()->orderBy('sort_order')->get();

        $this->assertSame([$b->id, $c->id], $usages->pluck('media_id')->all());
        $this->assertSame(0, $block->mediaUsages()->where('media_id', $a->id)->count(), 'the stale row must go');
        $this->assertSame(0, $a->fresh()->usages()->count(), 'and the media is free again');
    }

    // ----------------------------------------------------------------- 3

    public function test_the_same_media_in_two_blocks_produces_two_usage_rows(): void
    {
        $project = Project::factory()->create();
        $first = $this->block($project);
        $second = $this->block($project, BlockType::FullWidthImage);
        $media = $this->media();

        app(SyncBlockMedia::class)($first, [$media->id]);
        app(SyncBlockMedia::class)($second, [$media->id]);

        $this->assertSame(2, $media->fresh()->usages()->count());

        // And the same image twice inside one block is legal too.
        app(SyncBlockMedia::class)($first, [$media->id, $media->id]);

        $this->assertSame(
            [0, 1],
            $first->mediaUsages()->orderBy('sort_order')->pluck('sort_order')->all()
        );
        $this->assertSame(3, $media->fresh()->usages()->count());
    }

    // ----------------------------------------------------------------- 4

    public function test_media_used_only_inside_a_block_cannot_be_deleted(): void
    {
        $block = $this->block();
        $media = $this->media();
        $user = $this->admin();

        $this->assertTrue($user->can('delete', $media), 'unused media starts deletable');

        app(SyncBlockMedia::class)($block, [$media->id]);

        $response = Gate::forUser($user)->inspect('delete', $media->fresh());

        $this->assertFalse($response->allowed());
        $this->assertSame(
            'This image is still used in 1 place. Remove it there first, then delete it.',
            $response->message()
        );

        $this->actingAs($user)
            ->delete(route('admin.media.destroy', $media))
            ->assertForbidden();

        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------------- 5

    public function test_deleting_a_block_cascades_its_usages_and_frees_the_media(): void
    {
        $block = $this->block();
        $media = $this->media();
        $user = $this->admin();

        app(SyncBlockMedia::class)($block, [$media->id]);
        $this->assertSame(1, $media->fresh()->usages()->count());
        $this->assertFalse($user->can('delete', $media->fresh()));

        $block->delete();

        // The foreign key on media_usages.usable_id is polymorphic, so there
        // is no database cascade — the model must clean up after itself.
        $this->assertSame(0, $media->fresh()->usages()->count(), 'usage rows must not outlive the block');
        $this->assertTrue($user->can('delete', $media->fresh()), 'and the media becomes deletable again');
    }

    // ----------------------------------------------------------------- 6

    public function test_writing_a_media_id_into_data_throws(): void
    {
        $project = Project::factory()->create();

        foreach (['media_id', 'media_ids', 'image', 'imageId', 'photo_ids', 'MEDIA'] as $key) {
            try {
                $project->blocks()->create([
                    'type' => BlockType::FullWidthImage,
                    'sort_order' => 0,
                    'data' => [$key => 1],
                ]);

                $this->fail("Storing '{$key}' in data should have been refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('media_usages', $e->getMessage());
            }
        }

        $this->assertSame(0, ProjectBlock::count(), 'nothing may have been persisted');

        // Legitimate keys still work.
        $block = $project->blocks()->create([
            'type' => BlockType::Quote,
            'sort_order' => 0,
            'data' => ['quote' => 'Light is everything.', 'attribution' => 'A photographer'],
        ]);

        $this->assertSame('Light is everything.', $block->fresh()->data['quote']);
    }

    // ----------------------------------------------------------------- 7

    public function test_block_media_reads_back_in_the_stored_order(): void
    {
        $block = $this->block();
        $ordered = [$this->media(), $this->media(), $this->media(), $this->media()];
        $ids = array_map(fn (Media $m): int => $m->id, $ordered);

        // Deliberately not ascending, so an accidental orderBy('id') fails.
        $scrambled = [$ids[2], $ids[0], $ids[3], $ids[1]];

        app(SyncBlockMedia::class)($block, $scrambled);

        $readBack = $block->fresh()->load('blockMedia')->media();

        $this->assertSame($scrambled, $readBack->pluck('id')->all());
        $this->assertTrue($readBack->first()->variants->isNotEmpty(), 'variants come eager-loaded');
    }
}
