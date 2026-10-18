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
            // TASK 36c B3a. See real().
            'is_demo' => true,
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

    /*
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36c B3a — is_demo DEFAULTS TO TRUE, AND THAT IS THE WHOLE GUARD.
     *
     * Six faker-built projects were found in the development database
     * carrying is_demo = false. Nothing had gone wrong in any one step: the
     * factory never set the column, the migration default is false, and
     * `false` is the value every demo-aware tool in this project reads as
     * "the studio owns this, do not touch it". So a tinker session produced
     * rows the seeders would not refresh, the demo purge would not remove,
     * the dashboard counted as the client's own untranslated work — and one
     * of which was PUBLISHED, with lorem ipsum on the public site.
     *
     * The guard is not a prohibition on factories. It is this default: a
     * factory builds DEMO content unless somebody asks otherwise in writing.
     * Real content comes from the panel, and the panel sets the column
     * correctly because an editor creating a record is not using a factory.
     *
     * ->real() is the named exception, for a test that needs a row the
     * seeders must not own. FactoryDemoDefaultTest asserts this property
     * across every factory whose table has the column, discovered rather
     * than listed.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function real(): static
    {
        return $this->state(fn (): array => ['is_demo' => false]);
    }
}
