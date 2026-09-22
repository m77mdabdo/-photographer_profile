<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * A fully processed, ready-to-serve original.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();
        $extension = 'jpg';

        return [
            'uuid' => $uuid,
            'disk' => 'media_originals',
            'path' => 'originals/'.now()->format('Y/m').'/'.$uuid.'.'.$extension,
            'original_filename' => Str::slug($this->faker->words(3, true)).'.'.$extension,
            'extension' => $extension,
            'mime_type' => 'image/jpeg',
            'size_bytes' => $this->faker->numberBetween(4_500_000, 5_500_000),
            'checksum' => hash('sha256', $uuid),
            'width' => 4000,
            'height' => 2667,
            'status' => MediaStatus::Ready,
            'processed_at' => now(),
            'failure_reason' => null,
            'alt' => $this->faker->sentence(6),
            'caption' => $this->faker->sentence(12),
            'credit' => $this->faker->name(),
            'copyright' => '© '.now()->year.' '.$this->faker->lastName(),
            'focal_x' => 0.5,
            'focal_y' => 0.5,
            'lqip' => null,
            'dominant_color' => $this->faker->hexColor(),
            'uploaded_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MediaStatus::Pending,
            'processed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MediaStatus::Failed,
            'processed_at' => null,
            'failure_reason' => 'Unsupported colour profile.',
        ]);
    }
}
