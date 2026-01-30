<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\FicClient;
use Illuminate\Database\Seeder;

class ActionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all clients or create some if none exist
        $clients = FicClient::all();

        if ($clients->isEmpty()) {
            $this->command->warn('No FicClients found. Please run FicClient seeder first or create some clients.');

            return;
        }

        $this->command->info('Creating actions for existing clients...');

        // Create 3-5 actions for each client
        foreach ($clients->take(5) as $client) {
            $actionCount = rand(3, 5);

            $this->command->info("Creating {$actionCount} actions for client: {$client->name}");

            // Create actions with different dates over the last 6 months
            for ($i = 0; $i < $actionCount; $i++) {
                $daysAgo = rand(1, 180);
                $createdAt = now()->subDays($daysAgo);

                Action::factory()
                    ->forClient($client)
                    ->createdOn($createdAt)
                    ->create();
            }
        }

        $this->command->info('Actions seeded successfully!');

        // Show summary
        $totalActions = Action::count();
        $this->command->info("Total actions created: {$totalActions}");

        // Create a specific test action with exact values from the example
        $firstClient = $clients->first();
        Action::create([
            'fic_client_id' => $firstClient->id,
            'category' => 'IT',
            'name' => 'Setup webserver + DNS',
            'description' => 'Installazione ambiente Linux e dipendenze. Configurazione dei DSN',
            'gross' => 100.00,
            'created_at' => now()->subDays(7),
        ]);

        $this->command->info("Created example action for client: {$firstClient->name}");
    }
}
