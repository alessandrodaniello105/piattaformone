<?php

namespace Database\Factories;

use App\Models\FicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FicAccount>
 */
class FicAccountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = FicAccount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'company_id' => $this->faker->unique()->numberBetween(1000000, 9999999),
            'company_name' => $this->faker->company(),
            'company_email' => $this->faker->companyEmail(),
            'access_token' => 'test-access-token-' . $this->faker->sha256(),
            'refresh_token' => 'test-refresh-token-' . $this->faker->sha256(),
            'token_expires_at' => now()->addHours(1),
            'token_refreshed_at' => now(),
            'status' => 'active',
            'webhook_enabled' => true,
            'connected_at' => now(),
        ];
    }
}
