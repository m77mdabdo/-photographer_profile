<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'standfirst' => $this->faker->sentence(12),
            'body' => collect(range(1, 3))
                ->map(fn (): string => $this->faker->paragraph(6))
                ->implode("\n\n"),
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
            'is_indexable' => true,
            'is_locked' => false,
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Draft,
            'published_at' => null,
        ]);
    }

    /** Reachable, but never offered to a search engine. */
    public function noindex(): self
    {
        return $this->state(fn (): array => ['is_indexable' => false]);
    }

    public function locked(): self
    {
        return $this->state(fn (): array => ['is_locked' => true]);
    }
}
