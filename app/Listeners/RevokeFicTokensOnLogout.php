<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;

/**
 * Handle user logout event for Fatture in Cloud integration.
 *
 * In a multi-tenant application, FIC accounts belong to teams, not individual users.
 * Therefore, we don't disconnect accounts when a user logs out.
 */
class RevokeFicTokensOnLogout
{

    /**
     * Handle the event.
     *
     * NOTE: In a multi-tenant application, we DON'T disconnect FIC accounts on logout.
     * FIC accounts belong to teams, not individual users. Multiple users in the same
     * team should be able to use the same FIC connection even if one user logs out.
     *
     * Accounts should only be disconnected when explicitly requested by a team admin
     * or when tokens expire/become invalid.
     */
    public function handle(Logout $event): void
    {
        // Multi-tenant safe: Do nothing on logout
        // FIC accounts are team-level resources, not user-level
        
        Log::debug('FIC OAuth: User logged out (accounts remain active for team)', [
            'user_id' => $event->user?->id,
        ]);
    }

}
