<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'label' => ['en' => $this->faker->word(), 'ar' => 'كلمة'],
            'type' => MenuItem::TYPE_ROUTE,
            'target' => 'home',
            'page_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
