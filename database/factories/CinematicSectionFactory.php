<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaStatus;
use App\Models\CinematicSection;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CinematicSection>
 */
class CinematicSectionFactory extends Factory
{
    protected $model = CinematicSection::class;

    public function definition(): array
    {
        return [
            // A VALID section by default: a featured image with a ready photograph.
            // A gallery needs four photographs; use ->gallery().
            'type' => CinematicSection::TYPE_IMAGE,
            'featured_media_id' => fn () => Media::factory()->create(['status' => MediaStatus::Ready])->getKey(),
            'title' => ['en' => 'Light, placed with intent.', 'ar' => 'ضوءٌ يوضع بقصد.'],
            'description' => ['en' => 'A short line beneath the title.', 'ar' => 'سطر قصير تحت العنوان.'],
            'is_active' => true,
            // Demo by default, like every factory with the column (FactoryDemoDefaultTest).
            'is_demo' => true,
            'sort_order' => 0,
            'light_direction' => 'ltr',
            'intensity' => 'balanced',
            'darkness' => 'deep',
            'pace' => 'natural',
            'overlay' => 'none',
            'mobile_layout' => 'grid',
            'animate' => true,
        ];
    }

    /** A section the studio owns. */
    public function real(): static
    {
        return $this->state(['is_demo' => false]);
    }

    /** A lighting gallery with $count ready photographs. */
    public function gallery(int $count = CinematicSection::GALLERY_MIN): static
    {
        return $this->state(['type' => CinematicSection::TYPE_GALLERY, 'featured_media_id' => null])
            ->afterCreating(function (CinematicSection $section) use ($count): void {
                foreach (range(0, $count - 1) as $i) {
                    $section->items()->create([
                        'media_id' => Media::factory()->create(['status' => MediaStatus::Ready])->getKey(),
                        'sort_order' => $i,
                    ]);
                }
            });
    }

    public function image(?int $mediaId = null): static
    {
        return $this->state(['type' => CinematicSection::TYPE_IMAGE, 'featured_media_id' => $mediaId]);
    }

    public function video(string $path, ?int $posterId = null): static
    {
        return $this->state(['type' => CinematicSection::TYPE_VIDEO, 'featured_media_id' => null, 'video_path' => $path, 'poster_media_id' => $posterId]);
    }
}
