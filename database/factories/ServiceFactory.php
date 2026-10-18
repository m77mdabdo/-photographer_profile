<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * Published by default, for the reason ArticleFactory states: a factory
     * whose default is invisible makes every test that forgets to publish look
     * like a bug in the code rather than in the test.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            // TASK 36c B3a. See real().
            'is_demo' => true,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'summary' => $this->faker->sentence(14),
            'description' => collect(range(1, 3))
                ->map(fn (): string => $this->faker->paragraph(5))
                ->implode("\n\n"),
            'price_from_pence' => $this->faker->numberBetween(10, 300) * 1000,
            'price_note' => 'per day',
            'status' => PublishStatus::Published,
            'published_at' => now()->subDays($this->faker->numberBetween(1, 200)),
            'sort_order' => 0,
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Draft,
            'published_at' => null,
        ]);
    }

    /** A service quoted rather than priced — the case the page must hold. */
    public function quoted(): self
    {
        return $this->state(fn (): array => [
            'price_from_pence' => null,
            'price_note' => null,
        ]);
    }

    /*
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36c B3a — is_demo DEFAULTS TO TRUE, AND THAT IS THE WHOLE GUARD.
     *
     * Six faker-built projects were found in the development database
     * carrying is_demo = false. Nothing had gone wrong in any one step: this
     * factory simply never set the column, the migration default is false,
     * and `false` is the value every demo-aware tool in this project reads as
     * "the studio owns this, do not touch it". So a tinker session produced
     * rows that the seeders would not refresh, the demo purge would not
     * remove, the dashboard counted as the client's untranslated work, and
     * one of which was PUBLISHED with lorem ipsum on the public site.
     *
     * The guard is not a prohibition on factories. It is this default: a
     * factory builds demo content unless somebody asks otherwise, in writing.
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

    /** Kept for the call sites that say it explicitly; it is now the default. */
    public function demo(): self
    {
        return $this->state(fn (): array => ['is_demo' => true]);
    }
}
