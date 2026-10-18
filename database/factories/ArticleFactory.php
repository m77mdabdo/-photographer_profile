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
            // TASK 36c B3a. See real().
            'is_demo' => true,
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
