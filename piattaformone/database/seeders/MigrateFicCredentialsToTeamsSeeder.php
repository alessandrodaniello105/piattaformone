<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class MigrateFicCredentialsToTeamsSeeder extends Seeder
{
    /**
     * Migrate FIC OAuth credentials from .env to teams table.
     *
     * This seeder takes the hardcoded credentials from config and applies them
     * to the first team, enabling multi-tenant support.
     */
    public function run(): void
    {
        $defaultClientId = config('fattureincloud.client_id');
        $defaultClientSecret = config('fattureincloud.client_secret');
        $defaultRedirectUri = config('fattureincloud.redirect_uri');
        $defaultScopes = config('fattureincloud.scopes', []);

        if (!$defaultClientId || !$defaultClientSecret) {
            $this->command->warn('⚠️  No FIC credentials found in config. Skipping.');
            return;
        }

        // Get the first team (should be the main team)
        $mainTeam = Team::first();

        if (!$mainTeam) {
            $this->command->error('❌ No teams found in database.');
            return;
        }

        // Check if already configured
        if ($mainTeam->hasFicCredentials()) {
            $this->command->info("ℹ️  Team '{$mainTeam->name}' already has FIC credentials configured.");
            $this->command->info("   Client ID: {$mainTeam->fic_client_id}");
            
            // Ask if they want to overwrite
            if (!$this->command->confirm('Do you want to overwrite with credentials from .env?', false)) {
                return;
            }
        }

        // Ensure scopes are not empty (FIC requires at least one scope)
        if (empty($defaultScopes)) {
            $defaultScopes = [
                'entity:clients:r',
                'entity:suppliers:r',
                'issued_documents:quotes:r',
                'issued_documents:invoices:r',
            ];
            $this->command->warn('⚠️  No scopes in config, using defaults.');
        }
        
        // Apply credentials to main team
        $mainTeam->update([
            'fic_client_id' => $defaultClientId,
            'fic_client_secret' => $defaultClientSecret,
            'fic_redirect_uri' => $defaultRedirectUri,
            'fic_scopes' => $defaultScopes,
            'fic_configured_at' => now(),
        ]);

        $this->command->info("✅ FIC credentials migrated to team '{$mainTeam->name}' (ID: {$mainTeam->id})");
        $this->command->info("   Client ID: {$defaultClientId}");
        $this->command->info("   Redirect URI: {$defaultRedirectUri}");
        $this->command->info("   Scopes: " . implode(', ', $defaultScopes));
    }
}
