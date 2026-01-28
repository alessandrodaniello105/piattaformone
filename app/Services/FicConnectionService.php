<?php

namespace App\Services;

use App\Models\FicAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Service to manage FattureInCloud connection status for authenticated users.
 *
 * Handles checking connection validity, token expiration, and caching
 * for performance optimization.
 */
class FicConnectionService
{
    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'fic_connection_check_';

    /**
     * Check the FIC connection status for the given user.
     *
     * Returns comprehensive status information including connection state,
     * account details, and whether OAuth is needed.
     *
     * @param  User|null  $user
     * @return array{connected: bool, account_id: int|null, company_name: string|null, status: string|null, token_expired: bool, needs_oauth: bool}
     */
    public function checkConnectionStatus(?User $user): array
    {
        if (!$user) {
            return $this->getDefaultStatus();
        }

        // Try cache first
        $cacheKey = $this->getCacheKey($user);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Get account for user's current team
        $account = $this->getActiveFicAccount($user);

        if (!$account) {
            $status = [
                'connected' => false,
                'account_id' => null,
                'company_name' => null,
                'status' => null,
                'token_expired' => false,
                'needs_oauth' => true,
            ];
        } else {
            $tokenExpired = $this->isTokenExpired($account);
            $needsReauth = $account->needsReauth();

            $status = [
                'connected' => !$needsReauth && !$tokenExpired,
                'account_id' => $account->id,
                'company_name' => $account->company_name,
                'status' => $account->status,
                'token_expired' => $tokenExpired,
                'needs_oauth' => $needsReauth || $tokenExpired,
            ];
        }

        // Cache the result
        Cache::put($cacheKey, $status, self::CACHE_TTL);

        return $status;
    }

    /**
     * Get the active FicAccount for the user's current team.
     *
     * @param  User|null  $user
     * @return FicAccount|null
     */
    public function getActiveFicAccount(?User $user): ?FicAccount
    {
        if (!$user) {
            return null;
        }

        return FicAccount::forTeam($user->current_team_id)
            ->active()
            ->first();
    }

    /**
     * Check if the account's token is expired.
     *
     * @param  FicAccount  $account
     * @return bool
     */
    public function isTokenExpired(FicAccount $account): bool
    {
        return $account->isTokenExpired();
    }

    /**
     * Check if the user needs to perform OAuth authentication.
     *
     * @param  User|null  $user
     * @return bool
     */
    public function needsOAuth(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $status = $this->checkConnectionStatus($user);
        return $status['needs_oauth'];
    }

    /**
     * Clear the cached connection status for the user.
     *
     * Should be called when:
     * - OAuth callback completes
     * - User switches teams
     * - Account is manually updated
     *
     * @param  User  $user
     * @return void
     */
    public function clearCache(User $user): void
    {
        $cacheKey = $this->getCacheKey($user);
        Cache::forget($cacheKey);
    }

    /**
     * Get the cache key for the user's connection status.
     *
     * Includes both user_id and team_id to ensure proper isolation
     * when switching teams.
     *
     * @param  User  $user
     * @return string
     */
    private function getCacheKey(User $user): string
    {
        return self::CACHE_PREFIX . $user->id . '_' . $user->current_team_id;
    }

    /**
     * Get default status when user is not authenticated or has no team.
     *
     * @return array{connected: bool, account_id: null, company_name: null, status: null, token_expired: bool, needs_oauth: bool}
     */
    private function getDefaultStatus(): array
    {
        return [
            'connected' => false,
            'account_id' => null,
            'company_name' => null,
            'status' => null,
            'token_expired' => false,
            'needs_oauth' => false, // Don't show OAuth prompt for guests
        ];
    }
}
