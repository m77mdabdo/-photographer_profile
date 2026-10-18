<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'url' => 'https://'.$this->faker->domainName(),
            'sort_order' => 0,
            'is_active' => true,
            // TASK 36c B3a. See real().
            'is_demo' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /*
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36c B3a — is_demo DEFAULTS TO TRUE, AND THAT IS THE WHOLE GUARD.
     *
     * Six faker-built projects were found in the development database
     * carrying is_demo = false. Nothing had gone wrong in any one step: the
     * factory never set the column, the migration default is false, and
     * `false` is the value every demo-aware tool in this project reads as
     * "the studio owns this, do not touch it". So a tinker session produced
     * rows the seeders would not refresh, the demo purge would not remove,
     * the dashboard counted as the client's own untranslated work — and one
     * of which was PUBLISHED, with lorem ipsum on the public site.
     *
     * The guard is not a prohibition on factories. It is this default: a
     * factory builds DEMO content unless somebody asks otherwise in writing.
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
}
