<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title($this->faker->unique()->words(3, true));

        return [
            // TASK 36c B3a. See real().
            'is_demo' => true,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'subtitle' => $this->faker->sentence(4),
            'excerpt' => $this->faker->sentence(12),
            'description' => $this->faker->paragraphs(2, true),
            'year' => $this->faker->numberBetween(2015, 2026),
            'location' => $this->faker->city(),
            'client' => $this->faker->company(),
            'status' => PublishStatus::Draft,
            'published_at' => null,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublishStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublishStatus::Archived,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes): array => ['is_featured' => true]);
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
