<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team.
     */
    public function delete(Team $team): void
    {
        DB::transaction(function () use ($team) {
            // Delete all FIC accounts associated with this team
            // This will cascade delete all related data (subscriptions, clients, invoices, etc.)
            $ficAccounts = $team->ficAccounts()->get();

            foreach ($ficAccounts as $ficAccount) {
                Log::info('Deleting FIC account during team deletion', [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'fic_account_id' => $ficAccount->id,
                    'company_id' => $ficAccount->company_id,
                    'company_name' => $ficAccount->company_name,
                ]);

                // Delete the FIC account (cascade will handle related data)
                $ficAccount->delete();
            }

            // Delete the team itself
            $team->purge();
        });
    }
}
