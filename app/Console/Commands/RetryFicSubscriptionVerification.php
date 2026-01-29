<?php

namespace App\Console\Commands;

use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to retry verification for unverified FIC subscriptions.
 *
 * When a subscription verification fails (e.g., due to timing issues or network problems),
 * FIC allows requesting a new verification attempt using the verify endpoint.
 */
class RetryFicSubscriptionVerification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:retry-verification
                            {--subscription-id= : Only retry specific local subscription ID}
                            {--account-id= : Only retry subscriptions for specific account ID}
                            {--all : Retry verification for all active subscriptions (not just unverified)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry verification for unverified FIC webhook subscriptions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $subscriptionId = $this->option('subscription-id');
        $accountId = $this->option('account-id');
        $retryAll = $this->option('all');

        // Build query
        $query = FicSubscription::query()->where('is_active', true);

        if ($subscriptionId) {
            $query->where('id', $subscriptionId);
        }

        if ($accountId) {
            $query->where('fic_account_id', $accountId);
        }

        $subscriptions = $query->with('ficAccount')->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info('Found '.$subscriptions->count().' subscription(s)');

        // Fetch verification status from FIC API first
        $this->info('Fetching current verification status from FIC API...');
        $unverifiedSubscriptions = $this->fetchUnverifiedSubscriptions($subscriptions, $retryAll);

        if ($unverifiedSubscriptions->isEmpty()) {
            $this->info('All subscriptions are already verified!');

            return Command::SUCCESS;
        }

        $this->warn('Found '.$unverifiedSubscriptions->count().' unverified subscription(s)');
        $this->newLine();

        $successCount = 0;
        $errorCount = 0;

        foreach ($unverifiedSubscriptions as $subscription) {
            $account = $subscription->ficAccount;
            $ficSubId = $subscription->fic_subscription_id;

            $this->info("Retrying verification for subscription: {$ficSubId} (Account: {$account->company_name})");

            try {
                $apiService = new FicApiService($account);
                $result = $apiService->verifySubscription($ficSubId, 'header');

                if ($result) {
                    $this->line('  ✓ Verification request sent successfully');
                    $this->line('  → FIC will send a verification challenge to your webhook endpoint');
                    $successCount++;
                } else {
                    $this->warn('  ✗ Verification request failed (no exception but returned false)');
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $this->error('  ✗ Error: '.$e->getMessage());
                $errorCount++;
                Log::error('Retry FIC Subscription Verification: Error', [
                    'subscription_id' => $subscription->id,
                    'fic_subscription_id' => $ficSubId,
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->newLine();
        }

        // Summary
        $this->info('=== Summary ===');
        $this->line("Verification requests sent: {$successCount}");
        if ($errorCount > 0) {
            $this->warn("Errors: {$errorCount}");
        }
        $this->newLine();
        $this->info('Note: After verification, FIC will send a Welcome Event to confirm the subscription is verified.');
        $this->info('You can check verification status with: sail artisan fic:diagnose-webhooks');

        return Command::SUCCESS;
    }

    /**
     * Fetch unverified subscriptions from FIC API.
     *
     * @param  \Illuminate\Support\Collection  $subscriptions
     * @param  bool  $retryAll  Whether to retry all subscriptions or only unverified ones
     * @return \Illuminate\Support\Collection
     */
    private function fetchUnverifiedSubscriptions($subscriptions, bool $retryAll)
    {
        $unverified = collect();

        // Group by account to minimize API calls
        $byAccount = $subscriptions->groupBy('fic_account_id');

        foreach ($byAccount as $accountId => $accountSubscriptions) {
            $account = $accountSubscriptions->first()->ficAccount;

            if (! $account) {
                $this->warn("  Account not found for subscription group (account_id: {$accountId})");

                continue;
            }

            try {
                $apiService = new FicApiService($account);
                $apiSubscriptions = $apiService->fetchSubscriptions();

                // Create a map of subscription ID => verified status
                $verificationMap = collect($apiSubscriptions)->mapWithKeys(function ($sub) {
                    return [$sub['id'] => $sub['verified'] ?? false];
                });

                foreach ($accountSubscriptions as $subscription) {
                    $ficSubId = $subscription->fic_subscription_id;
                    $verified = $verificationMap->get($ficSubId, false);

                    if ($retryAll || ! $verified) {
                        $unverified->push($subscription);
                        $status = $verified ? 'verified (retrying anyway due to --all)' : 'NOT verified';
                        $this->line("  - {$ficSubId}: {$status}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("  Error fetching subscriptions for account {$account->company_name}: ".$e->getMessage());
                Log::error('Retry Verification: Error fetching subscriptions', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $unverified;
    }
}
