<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function variantAttributes(array $overrides = []): array
    {
        return array_merge([
            'format' => 'webp',
            'width' => 1200,
            'height' => 800,
            'disk' => 'media_public',
            'path' => 'variants/1200.webp',
            'size_bytes' => 180_000,
        ], $overrides);
    }

    public function test_creating_media_auto_assigns_a_uuid(): void
    {
        $media = Media::factory()->create(['uuid' => null]);

        $this->assertNotNull($media->uuid);
        $this->assertTrue(Str::isUuid($media->uuid));
        $this->assertDatabaseHas('media', ['id' => $media->id, 'uuid' => $media->uuid]);
    }

    public function test_an_explicit_uuid_is_not_overwritten(): void
    {
        $uuid = (string) Str::uuid();

        $media = Media::factory()->create(['uuid' => $uuid]);

        $this->assertSame($uuid, $media->uuid);
        $this->assertSame('uuid', $media->getRouteKeyName());
    }

    public function test_status_is_cast_to_the_media_status_enum(): void
    {
        $media = Media::factory()->create(['status' => MediaStatus::Ready]);

        $fresh = $media->fresh();

        $this->assertInstanceOf(MediaStatus::class, $fresh->status);
        $this->assertSame(MediaStatus::Ready, $fresh->status);
        $this->assertSame('Ready', $fresh->status->label());
        $this->assertTrue($fresh->status->isUsable());
        $this->assertTrue($fresh->isReady());

        $pending = Media::factory()->pending()->create()->fresh();

        $this->assertSame(MediaStatus::Pending, $pending->status);
        $this->assertSame('In queue', $pending->status->label());
        $this->assertFalse($pending->status->isUsable());
        $this->assertFalse($pending->isReady());
    }

    public function test_ready_scope_returns_only_ready_records(): void
    {
        $ready = Media::factory()->count(2)->create(['status' => MediaStatus::Ready]);
        Media::factory()->pending()->create();
        Media::factory()->failed()->create();
        Media::factory()->create(['status' => MediaStatus::Processing]);

        $results = Media::ready()->get();

        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            $ready->pluck('id')->all(),
            $results->pluck('id')->all()
        );
    }

    public function test_media_can_have_variants_and_the_format_width_pair_is_unique(): void
    {
        $media = Media::factory()->create();

        $variant = $media->variants()->create($this->variantAttributes());

        $this->assertInstanceOf(MediaVariant::class, $variant);
        $this->assertCount(1, $media->refresh()->variants);
        $this->assertTrue($variant->media->is($media));
        $this->assertSame(1200, $variant->width);

        // A different width for the same format is allowed.
        $media->variants()->create($this->variantAttributes([
            'width' => 600,
            'height' => 400,
            'path' => 'variants/600.webp',
        ]));
        $this->assertCount(2, $media->refresh()->variants);

        // The same [media_id, format, width] triple is not.
        $this->expectException(QueryException::class);
        $media->variants()->create($this->variantAttributes());
    }

    public function test_force_deleting_media_cascades_variants_in_the_database(): void
    {
        $media = Media::factory()->create();
        $variant = $media->variants()->create($this->variantAttributes());

        $this->assertDatabaseHas('media_variants', ['id' => $variant->id]);

        // A soft delete must leave the variant rows in place.
        $media->delete();
        $this->assertDatabaseHas('media_variants', ['id' => $variant->id]);

        // A hard delete must remove them via the foreign key.
        $media->forceDelete();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('media_variants', ['id' => $variant->id]);
    }

    public function test_a_usage_can_be_attached_polymorphically_and_read_back(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->create();

        $usage = $media->usages()->create([
            'usable_type' => User::class,
            'usable_id' => $user->id,
            'role' => 'portrait',
            'sort_order' => 3,
        ]);

        $media->refresh();

        $this->assertCount(1, $media->usages);
        $this->assertSame($usage->id, $media->usages->first()->id);
        $this->assertSame('portrait', $media->usages->first()->role);
        $this->assertSame(3, $media->usages->first()->sort_order);
        $this->assertTrue($media->usages->first()->usable->is($user));
        $this->assertTrue($usage->media->is($media));
    }

    public function test_aspect_ratio_is_computed_and_null_without_usable_dimensions(): void
    {
        $media = Media::factory()->create(['width' => 4000, 'height' => 2667]);

        $this->assertEqualsWithDelta(4000 / 2667, $media->aspectRatio(), 0.000001);

        $noHeight = Media::factory()->create(['width' => 4000, 'height' => null]);
        $this->assertNull($noHeight->aspectRatio());

        $noWidth = Media::factory()->create(['width' => null, 'height' => 2667]);
        $this->assertNull($noWidth->aspectRatio());

        $zeroHeight = Media::factory()->create(['width' => 4000, 'height' => 0]);
        $this->assertNull($zeroHeight->aspectRatio());
    }
}
