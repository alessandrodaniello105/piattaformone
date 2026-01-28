<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Jetstream;

class CreateTeam implements CreatesTeams
{
    /**
     * Validate and create a new team for the given user.
     *
     * @param  array<string, string>  $input
     */
    public function create(User $user, array $input): Team
    {
        Gate::forUser($user)->authorize('create', Jetstream::newTeamModel());

        $validationRules = [
            'name' => ['required', 'string', 'max:255'],
            // Optional FIC credentials
            'fic_client_id' => ['nullable', 'string', 'max:255'],
            'fic_client_secret' => ['nullable', 'string'],
            'fic_redirect_uri' => ['nullable', 'url', 'max:500'],
            'fic_company_id' => ['nullable', 'string', 'max:255'],
            'fic_scopes' => ['nullable', 'array'],
            'fic_scopes.*' => ['string', 'in:entity:clients:r,entity:clients:a,entity:suppliers:r,entity:suppliers:a,issued_documents:invoices:r,issued_documents:invoices:a,issued_documents:quotes:r,issued_documents:quotes:a,settings:all'],
        ];

        Validator::make($input, $validationRules)->validateWithBag('createTeam');

        AddingTeam::dispatch($user);

        // Prepare team data
        $teamData = [
            'name' => $input['name'],
            'personal_team' => false,
        ];

        // Add FIC credentials if provided
        if (!empty($input['fic_client_id']) && !empty($input['fic_client_secret'])) {
            $teamData['fic_client_id'] = $input['fic_client_id'];
            $teamData['fic_client_secret'] = $input['fic_client_secret'];
            $teamData['fic_redirect_uri'] = $input['fic_redirect_uri'] ?? config('fattureincloud.redirect_uri');
            $teamData['fic_company_id'] = $input['fic_company_id'] ?? null;
            $teamData['fic_scopes'] = $input['fic_scopes'] ?? config('fattureincloud.scopes', []);
            $teamData['fic_configured_at'] = now();
        }

        $user->switchTeam($team = $user->ownedTeams()->create($teamData));

        return $team;
    }
}
