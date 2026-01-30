<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\FicClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Action>
 */
class ActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = ['IT', 'Consulenza', 'Sviluppo', 'Manutenzione', 'Supporto', 'Formazione'];
        $itServices = [
            ['name' => 'Setup webserver + DNS', 'description' => 'Installazione ambiente Linux e dipendenze. Configurazione dei DNS'],
            ['name' => 'Configurazione SSL/TLS', 'description' => 'Installazione e configurazione certificati SSL con Let\'s Encrypt'],
            ['name' => 'Backup automatico', 'description' => 'Configurazione sistema di backup automatico giornaliero'],
            ['name' => 'Monitoraggio server', 'description' => 'Setup sistema di monitoraggio uptime e risorse'],
            ['name' => 'Aggiornamento sicurezza', 'description' => 'Applicazione patch di sicurezza e aggiornamenti sistema'],
        ];

        $consultingServices = [
            ['name' => 'Analisi requisiti', 'description' => 'Analisi dettagliata dei requisiti di business e tecnici'],
            ['name' => 'Revisione architettura', 'description' => 'Revisione architettura software e proposte di miglioramento'],
            ['name' => 'Consulenza tecnologica', 'description' => 'Consulenza su scelte tecnologiche e best practices'],
        ];

        $developmentServices = [
            ['name' => 'Sviluppo API REST', 'description' => 'Sviluppo endpoint API RESTful con documentazione'],
            ['name' => 'Integrazione pagamenti', 'description' => 'Integrazione gateway pagamenti Stripe/PayPal'],
            ['name' => 'Dashboard analytics', 'description' => 'Sviluppo dashboard per visualizzazione dati e statistiche'],
        ];

        $category = fake()->randomElement($categories);

        // Select service based on category
        if ($category === 'IT') {
            $service = fake()->randomElement($itServices);
        } elseif ($category === 'Consulenza') {
            $service = fake()->randomElement($consultingServices);
        } else {
            $service = fake()->randomElement($developmentServices);
        }

        return [
            'fic_client_id' => FicClient::factory(),
            'category' => $category,
            'name' => $service['name'],
            'description' => $service['description'],
            'gross' => fake()->randomFloat(2, 50, 500),
            'created_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }

    /**
     * Indicate that the action is for IT category.
     */
    public function it(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'IT',
            'name' => 'Setup webserver + DNS',
            'description' => 'Installazione ambiente Linux e dipendenze. Configurazione dei DNS',
            'gross' => 100.00,
        ]);
    }

    /**
     * Indicate that the action is for a specific client.
     */
    public function forClient(FicClient $client): static
    {
        return $this->state(fn (array $attributes) => [
            'fic_client_id' => $client->id,
        ]);
    }

    /**
     * Indicate that the action was created on a specific date.
     */
    public function createdOn(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $date,
        ]);
    }
}
