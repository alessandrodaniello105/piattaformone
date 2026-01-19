<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use FattureInCloud\Configuration;
use FattureInCloud\Api\UserApi;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Command to sync FicAccount from Redis tokens.
 *
 * Useful if OAuth was completed but account creation failed.
 * This command reads tokens from Redis and creates/updates the account.
 */
class SyncFicAccountFromRedis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:sync-account-from-redis';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync FicAccount from Redis tokens (useful if OAuth completed but account creation failed)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Syncing FicAccount from Redis tokens...');
        $this->newLine();

        // Get tokens from Redis
        $accessToken = Redis::get('fic:oauth:access_token');
        $refreshToken = Redis::get('fic:oauth:refresh_token');
        $tokenExpiresAtString = Redis::get('fic:oauth:token_expires_at');

        if (!$accessToken) {
            $this->error('No access token found in Redis.');
            $this->line('Please complete the OAuth flow first by visiting /api/fic/oauth/redirect');
            return Command::FAILURE;
        }

        $this->info('✓ Found tokens in Redis');
        $this->line("  Access token: " . substr($accessToken, 0, 20) . '...');
        $this->line("  Refresh token: " . (!empty($refreshToken) ? substr($refreshToken, 0, 20) . '...' : 'Not found'));

        $tokenExpiresAt = $tokenExpiresAtString ? \Carbon\Carbon::parse($tokenExpiresAtString) : null;
        if ($tokenExpiresAt) {
            $this->line("  Expires at: {$tokenExpiresAt->format('Y-m-d H:i:s')}");
        }

        $this->newLine();

        try {
            // Initialize FIC SDK configuration
            $config = Configuration::getDefaultConfiguration();
            $config->setAccessToken($accessToken);

            $httpClient = new Client([
                'timeout' => 30.0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Get user companies
            $this->info('Fetching company information from FIC API...');
            $userApi = new UserApi($httpClient, $config);
            $response = $userApi->listUserCompanies();
            $companiesData = $response->getData();
            $companies = $companiesData ? $companiesData->getCompanies() : [];

            if (empty($companies)) {
                $this->error('No companies found for this user.');
                return Command::FAILURE;
            }

            // Use the first company
            $company = is_array($companies) ? $companies[0] : $companies;
            $companyId = is_object($company) ? $company->getId() : $company['id'] ?? null;
            $companyName = is_object($company) ? $company->getName() : $company['name'] ?? null;

            if (!$companyId) {
                $this->error('Company ID not found in API response.');
                return Command::FAILURE;
            }

            $this->info("✓ Found company: {$companyName} (ID: {$companyId})");
            $this->newLine();

            // Find or create account
            $account = FicAccount::firstOrNew([
                'company_id' => $companyId,
            ]);

            $isNew = !$account->exists;

            // Update account with tokens and company info
            $account->fill([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $tokenExpiresAt,
                'token_refreshed_at' => now(),
                'company_name' => $companyName,
                'company_email' => null,
                'status' => 'active',
                'connected_at' => $account->connected_at ?? now(),
            ]);

            if (empty($account->name)) {
                $account->name = $companyName ?? "Account {$companyId}";
            }

            $account->save();

            $this->info($isNew ? '✓ Account created successfully!' : '✓ Account updated successfully!');
            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                [
                    ['Account ID', $account->id],
                    ['Company ID (FIC)', $account->company_id],
                    ['Company Name', $account->company_name],
                    ['Name', $account->name],
                    ['Status', $account->status],
                    ['Connected At', $account->connected_at ? $account->connected_at->format('Y-m-d H:i:s') : 'N/A'],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error syncing account: ' . $e->getMessage());
            $this->line('Error class: ' . get_class($e));
            $this->line('File: ' . $e->getFile() . ':' . $e->getLine());

            Log::error('FIC Sync Account: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
