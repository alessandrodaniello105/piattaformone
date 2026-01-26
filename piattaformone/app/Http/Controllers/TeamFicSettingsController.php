<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TeamFicSettingsController extends Controller
{
    /**
     * Update FIC OAuth credentials for a team.
     *
     * Only team owner or admin can update these settings.
     */
    public function update(Request $request, Team $team)
    {
        // Authorization: Only team owner/admin can update FIC settings
        if (! Gate::forUser($request->user())->check('update', $team)) {
            abort(403, 'Non hai i permessi per modificare queste impostazioni.');
        }

        $validated = $request->validate([
            'fic_client_id' => 'required|string|max:255',
            'fic_client_secret' => 'required|string',
            'fic_redirect_uri' => 'nullable|url|max:500',
            'fic_company_id' => 'nullable|string|max:255',
            'fic_scopes' => 'nullable|array',
            'fic_scopes.*' => 'string|in:entity:clients:r,entity:clients:a,entity:suppliers:r,entity:suppliers:a,issued_documents:invoices:r,issued_documents:invoices:a,issued_documents:quotes:r,issued_documents:quotes:a,settings:all',
        ]);

        // Set default redirect URI if not provided
        if (empty($validated['fic_redirect_uri'])) {
            $validated['fic_redirect_uri'] = config('fattureincloud.redirect_uri');
        }

        // Set default scopes if not provided
        if (empty($validated['fic_scopes'])) {
            $validated['fic_scopes'] = config('fattureincloud.scopes');
        }

        // Add configuration timestamp
        $validated['fic_configured_at'] = now();

        // Update team settings
        $team->update($validated);

        return back()->with('success', 'Credenziali Fatture in Cloud salvate con successo!');
    }

    /**
     * Remove FIC OAuth credentials from a team.
     */
    public function destroy(Request $request, Team $team)
    {
        // Authorization: Only team owner/admin can remove FIC settings
        if (! Gate::forUser($request->user())->check('update', $team)) {
            abort(403, 'Non hai i permessi per modificare queste impostazioni.');
        }

        // Clear FIC credentials
        $team->update([
            'fic_client_id' => null,
            'fic_client_secret' => null,
            'fic_redirect_uri' => null,
            'fic_company_id' => null,
            'fic_scopes' => null,
            'fic_configured_at' => null,
        ]);

        return back()->with('success', 'Credenziali Fatture in Cloud rimosse con successo!');
    }
}
