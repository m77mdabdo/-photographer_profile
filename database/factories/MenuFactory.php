<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    /**
     * @return array<string, mixed>
     */
    /**
     * A location that is unique per row.
     *
     * `location` carries a unique index — two menus claiming the header is a
     * question with no right answer — so a factory that always returned
     * 'header' broke the moment a generic test created a second menu. The
     * two REAL locations have states of their own below; everything else is
     * a menu no template renders, which is inert and is what a fixture
     * wants.
     */
    public function definition(): array
    {
        return ['location' => 'fixture-'.$this->faker->unique()->numberBetween(1, 999999)];
    }

    public function header(): static
    {
        return $this->state(['location' => Menu::HEADER]);
    }

    public function footer(): static
    {
        return $this->state(['location' => Menu::FOOTER]);
    }
}
