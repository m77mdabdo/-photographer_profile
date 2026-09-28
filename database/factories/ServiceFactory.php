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

    public function demo(): self
    {
        return $this->state(fn (): array => ['is_demo' => true]);
    }
}
