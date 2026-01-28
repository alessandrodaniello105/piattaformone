<?php

namespace Database\Factories;

use App\Models\FicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FicEvent>
 */
class FicEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resourceType = $this->faker->randomElement(['client', 'quote', 'invoice']);
        $eventType = match ($resourceType) {
            'client' => 'it.fattureincloud.webhooks.entities.clients.create',
            'quote' => 'it.fattureincloud.webhooks.issued_documents.quotes.create',
            'invoice' => 'it.fattureincloud.webhooks.issued_documents.invoices.create',
            default => 'it.fattureincloud.webhooks.unknown',
        };

        return [
            'fic_account_id' => FicAccount::factory(),
            'event_type' => $eventType,
            'resource_type' => $resourceType,
            'fic_resource_id' => $this->faker->numberBetween(1000, 999999),
            'occurred_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'payload' => [
                'event' => $eventType,
                'data' => [
                    'ids' => [$this->faker->numberBetween(1000, 999999)],
                ],
            ],
        ];
    }
}
