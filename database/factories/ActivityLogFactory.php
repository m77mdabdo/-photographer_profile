<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'actor_name' => $this->faker->name(),
            'event' => 'published',
            'subject_type' => null,
            'subject_id' => null,
            'subject_label' => $this->faker->sentence(3),
            'properties' => null,
            'created_at' => now(),
        ];
    }
}
