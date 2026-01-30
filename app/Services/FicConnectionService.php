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
     * @param  bool  $autoRefreshCompanyInfo  Whether to automatically refresh company info if stale
     * @return array{connected: bool, account_id: int|null, company_name: string|null, status: string|null, token_expired: bool, needs_oauth: bool}
     */
    public function checkConnectionStatus(?User $user, bool $autoRefreshCompanyInfo = false): array
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

            // Optionally refresh company info if stale (default: 24 hours)
            if ($autoRefreshCompanyInfo && !$needsReauth && !$tokenExpired && $this->shouldRefreshCompanyInfo($account)) {
                try {
                    $this->refreshCompanyInfo($account, clearCache: false);
                    // Reload account to get updated data
                    $account->refresh();
                } catch (\Exception $e) {
                    // Log but don't fail the connection check
                    \Illuminate\Support\Facades\Log::warning('FIC Connection: Failed to auto-refresh company info', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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
     * Refresh company info from FIC API and update the FicAccount.
     *
     * Fetches latest company details (name, email, etc.) from FIC
     * and updates the local FicAccount record. Useful when company
     * details change in FattureInCloud.
     *
     * @param  FicAccount  $account  The account to refresh
     * @param  bool  $clearCache  Whether to clear connection status cache
     * @return array Company info data that was fetched
     *
     * @throws \Exception If the API call fails
     */
    public function refreshCompanyInfo(FicAccount $account, bool $clearCache = true): array
    {
        \Log::info('FIC Connection: Starting company info refresh', [
            'account_id' => $account->id,
            'current_company_name' => $account->company_name,
            'current_company_email' => $account->company_email,
        ]);

        // Create FicApiService for this account
        $apiService = new FicApiService($account);

        // Fetch company info from FIC
        $companyInfo = $apiService->fetchCompanyInfo();

        \Log::info('FIC Connection: Fetched company info from API', [
            'account_id' => $account->id,
            'fetched_data' => $companyInfo,
        ]);

        // Update FicAccount with latest info
        $updateData = [
            'company_name' => $companyInfo['name'] ?? $account->company_name,
            'company_email' => $companyInfo['email'] ?? $account->company_email,
        ];

        // Store additional metadata if available
        $metadata = $account->metadata ?? [];
        $metadata['company_info_last_updated'] = now()->toIso8601String();

        // Convert type object to string if it's an object
        if (isset($companyInfo['type'])) {
            $type = $companyInfo['type'];
            if (is_object($type) && method_exists($type, '__toString')) {
                $metadata['company_type'] = (string) $type;
            } elseif (is_object($type) && method_exists($type, 'getValue')) {
                $metadata['company_type'] = $type->getValue();
            } elseif (is_string($type)) {
                $metadata['company_type'] = $type;
            } else {
                $metadata['company_type'] = json_encode($type);
            }
        }

        // Convert plan name object to string if it's an object
        if (isset($companyInfo['fic_plan_name'])) {
            $planName = $companyInfo['fic_plan_name'];
            if (is_object($planName) && method_exists($planName, '__toString')) {
                $metadata['fic_plan_name'] = (string) $planName;
            } elseif (is_object($planName) && method_exists($planName, 'getValue')) {
                $metadata['fic_plan_name'] = $planName->getValue();
            } elseif (is_string($planName)) {
                $metadata['fic_plan_name'] = $planName;
            } else {
                $metadata['fic_plan_name'] = json_encode($planName);
            }
        }

        $updateData['metadata'] = $metadata;

        \Log::info('FIC Connection: Preparing to update account', [
            'account_id' => $account->id,
            'update_data' => $updateData,
        ]);

        // Perform the update
        $updated = $account->update($updateData);

        \Log::info('FIC Connection: Update result', [
            'account_id' => $account->id,
            'update_success' => $updated,
            'new_company_name' => $account->fresh()->company_name,
            'new_company_email' => $account->fresh()->company_email,
            'new_metadata' => $account->fresh()->metadata,
        ]);

        // Clear cache so next checkConnectionStatus() gets fresh data
        if ($clearCache && $account->team) {
            foreach ($account->team->users as $user) {
                $this->clearCache($user);
            }
        }

        return $companyInfo;
    }

    /**
     * Check if company info needs refresh based on last update time.
     *
     * Returns true if company info hasn't been checked in the specified period.
     *
     * @param  FicAccount  $account
     * @param  int  $hoursThreshold  Hours since last update (default: 24)
     * @return bool
     */
    public function shouldRefreshCompanyInfo(FicAccount $account, int $hoursThreshold = 24): bool
    {
        $metadata = $account->metadata ?? [];

        if (!isset($metadata['company_info_last_updated'])) {
            return true; // Never updated
        }

        try {
            $lastUpdated = \Carbon\Carbon::parse($metadata['company_info_last_updated']);
            return $lastUpdated->diffInHours(now()) >= $hoursThreshold;
        } catch (\Exception $e) {
            return true; // Invalid date, refresh needed
        }
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
