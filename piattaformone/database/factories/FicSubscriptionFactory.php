<?php

namespace Database\Factories;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FicSubscription>
 */
class FicSubscriptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = FicSubscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fic_account_id' => FicAccount::factory(),
            'fic_subscription_id' => 'sub_' . $this->faker->uuid(),
            'event_group' => $this->faker->randomElement(['entity', 'issued_documents', 'products', 'receipts']),
            'webhook_secret' => 'test-webhook-secret-' . $this->faker->sha256(),
            'expires_at' => now()->addDays(30),
            'is_active' => true,
        ];
    }
}
