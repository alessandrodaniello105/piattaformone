<?php

namespace Database\Factories;

use App\Models\FicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FicClient>
 */
class FicClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fic_account_id' => FicAccount::factory(),
            'fic_client_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'name' => $this->faker->company(),
            'code' => 'CLI-' . $this->faker->unique()->numberBetween(1000, 9999),
            'fic_created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'fic_updated_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'raw' => [
                'id' => $this->faker->numberBetween(1000, 999999),
                'name' => $this->faker->company(),
                'code' => 'CLI-' . $this->faker->numberBetween(1000, 9999),
            ],
        ];
    }
}
