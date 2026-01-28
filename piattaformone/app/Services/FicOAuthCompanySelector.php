<?php

namespace App\Services;

use App\Models\FicAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Selects which FIC company to use when processing OAuth callback.
 *
 * When a team already has an existing fic_account, we prefer that company
 * from the list returned by listUserCompanies. This avoids wrongly picking
 * the first company when the user has access to multiple FIC companies.
 */
class FicOAuthCompanySelector
{
    /**
     * Select which FIC company to use from the normalized list.
     *
     * If the team has an existing fic_account, its company_id must appear
     * in the list; otherwise we throw. If no existing account, we use the
     * first company.
     *
     * @param  array<int, array{id: int, name: string|null}>  $normalized  [['id' => int, 'name' => ?string], ...]
     * @param  int|string|null  $tenantId
     * @return array{id: int, name: string|null}
     *
     * @throws \RuntimeException
     */
    public static function select(array $normalized, $tenantId): array
    {
        if (empty($normalized)) {
            throw new \RuntimeException('Company ID not found in API response');
        }

        $existingForTeam = $tenantId !== null
            ? FicAccount::where('tenant_id', $tenantId)->first()
            : null;

        if ($existingForTeam !== null) {
            $expectedId = (int) $existingForTeam->company_id;
            $match = (new Collection($normalized))->firstWhere('id', $expectedId);
            if ($match !== null) {
                return $match;
            }
            $returnedIds = (new Collection($normalized))->pluck('id')->all();
            Log::warning('FIC OAuth: Authorized FIC account does not include team company', [
                'expected_company_id' => $expectedId,
                'returned_company_ids' => $returnedIds,
                'tenant_id' => $tenantId,
            ]);
            throw new \RuntimeException(
                "Hai autorizzato con un account Fatture in Cloud che non include l'azienda di questo team ".
                "(attesa: ID {$expectedId}). Aziende restituite: ".implode(', ', $returnedIds).'. '.
                "Esegui di nuovo l'OAuth selezionando l'azienda corretta in Fatture in Cloud."
            );
        }

        return $normalized[0];
    }
}
