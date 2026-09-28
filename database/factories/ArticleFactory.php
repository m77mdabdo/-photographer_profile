<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * Published by default.
     *
     * A factory whose default is invisible makes every test that forgets to
     * publish look like a bug in the code rather than in the test. Drafts are
     * the explicit state, via draft().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(4, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'subtitle' => $this->faker->sentence(8),
            'excerpt' => $this->faker->sentence(16),
            'body' => collect(range(1, 4))
                ->map(fn (): string => $this->faker->paragraph(6))
                ->implode("\n\n"),
            'status' => PublishStatus::Published,
            'published_at' => now()->subDays($this->faker->numberBetween(1, 400)),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Draft,
            'published_at' => null,
        ]);
    }

    /** Published, but not yet — a scheduled piece. */
    public function scheduled(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function demo(): self
    {
        return $this->state(fn (): array => ['is_demo' => true]);
    }
}
