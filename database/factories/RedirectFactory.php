<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_path' => '/'.Str::slug($this->faker->unique()->words(2, true)),
            'to_path' => '/'.Str::slug($this->faker->unique()->words(2, true)),
            'status_code' => 301,
            'is_active' => true,
            'source' => 'manual',
        ];
    }

    public function temporary(): self
    {
        return $this->state(fn (): array => ['status_code' => 302]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
