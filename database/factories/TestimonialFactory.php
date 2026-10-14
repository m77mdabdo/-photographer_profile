<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Testimonial>
 */
class TestimonialFactory extends Factory
{
    protected $model = Testimonial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote' => ['en' => $this->faker->sentence(12), 'ar' => 'اقتباس تجريبي من عميل.'],
            'attribution' => ['en' => $this->faker->name(), 'ar' => 'اسم العميل'],
            'role' => ['en' => $this->faker->jobTitle(), 'ar' => 'الصفة'],
            'media_id' => null,
            'status' => PublishStatus::Draft,
            'published_at' => null,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }
}
