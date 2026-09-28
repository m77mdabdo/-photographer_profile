<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inquiry>
 */
class InquiryFactory extends Factory
{
    protected $model = Inquiry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => InquiryStatus::New,
            'source' => 'contact_form',
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'company' => $this->faker->optional()->company(),
            'project_type' => $this->faker->randomElement(['Editorial', 'Portrait', 'Commission', 'Archive']),
            'shoot_date' => $this->faker->optional()->dateTimeBetween('+2 weeks', '+8 months'),
            'location' => $this->faker->optional()->city(),
            'budget_range' => $this->faker->randomElement(['Under £2,000', '£2,000–£5,000', '£5,000–£10,000', 'Over £10,000']),
            'heard_from' => $this->faker->randomElement(['A previous client', 'Instagram', 'A magazine', 'Search']),
            'message' => $this->faker->paragraphs(2, true),
            'consented_at' => now(),
            'ip_hash' => Inquiry::hashIp($this->faker->ipv4()),
        ];
    }

    public function status(InquiryStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'read_at' => $status === InquiryStatus::New ? null : now(),
        ]);
    }

    /** An enquiry with every optional field empty — the minimum a visitor can send. */
    public function minimal(): static
    {
        return $this->state(fn (): array => [
            'phone' => null, 'company' => null, 'project_type' => null,
            'shoot_date' => null, 'location' => null, 'budget_range' => null,
            'heard_from' => null,
        ]);
    }
}
