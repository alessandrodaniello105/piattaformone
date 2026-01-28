<?php

namespace Database\Factories;

use App\Models\FicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FicQuote>
 */
class FicQuoteFactory extends Factory
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
            'fic_quote_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'number' => 'PRE-' . $this->faker->unique()->numberBetween(1000, 9999),
            'status' => $this->faker->randomElement(['draft', 'sent', 'accepted', 'rejected', 'expired']),
            'total_gross' => $this->faker->randomFloat(2, 100, 10000),
            'fic_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'fic_created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'raw' => [
                'id' => $this->faker->numberBetween(1000, 999999),
                'number' => 'PRE-' . $this->faker->numberBetween(1000, 9999),
                'status' => $this->faker->randomElement(['draft', 'sent', 'accepted', 'rejected', 'expired']),
                'amount_net' => $this->faker->randomFloat(2, 100, 10000),
            ],
        ];
    }
}
