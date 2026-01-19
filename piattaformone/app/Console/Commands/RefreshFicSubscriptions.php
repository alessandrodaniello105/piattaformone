<?php

namespace App\Console\Commands;

use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to refresh FIC subscriptions that are expiring soon.
 *
 * This command finds active subscriptions expiring within 15 days
 * and renews them using the FicApiService.
 */
class RefreshFicSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:refresh-subscriptions
                            {--days=15 : Number of days ahead to check for expiration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh FIC subscriptions that are expiring within the specified days (default: 15)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->addDays($days);

        $this->info("Refreshing FIC subscriptions expiring within {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})...");
        $this->newLine();

        // Find subscriptions that are active and expiring within the specified days
        $subscriptions = FicSubscription::where('is_active', true)
            ->where('expires_at', '<=', $cutoffDate)
            ->whereNotNull('expires_at')
            ->with('ficAccount')
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions found that need renewal.');
            return Command::SUCCESS;
        }

        $this->info("Found {$subscriptions->count()} subscription(s) to renew:");
        $this->newLine();

        $successCount = 0;
        $errorCount = 0;

        foreach ($subscriptions as $subscription) {
            $this->line("Processing subscription ID: {$subscription->id} (Account: {$subscription->fic_account_id}, Group: {$subscription->event_group})");

            try {
                $account = $subscription->ficAccount;

                if (!$account) {
                    $this->error("  ✗ Account not found for subscription {$subscription->id}");
                    Log::error('FIC Refresh Subscriptions: Account not found', [
                        'subscription_id' => $subscription->id,
                        'account_id' => $subscription->fic_account_id,
                    ]);
                    $errorCount++;
                    continue;
                }

                // Check if account has access token
                if (empty($account->access_token)) {
                    $this->error("  ✗ Account {$account->id} has no access token");
                    Log::warning('FIC Refresh Subscriptions: Account missing access token', [
                        'subscription_id' => $subscription->id,
                        'account_id' => $account->id,
                    ]);
                    $errorCount++;
                    continue;
                }

                // Skip subscriptions that are already expired
                if ($subscription->expires_at && $subscription->expires_at->isPast()) {
                    $this->warn("  ⚠ Subscription already expired on {$subscription->expires_at->format('Y-m-d H:i:s')}");
                    Log::warning('FIC Refresh Subscriptions: Skipping expired subscription', [
                        'subscription_id' => $subscription->id,
                        'expires_at' => $subscription->expires_at,
                    ]);
                    continue;
                }

                // Generate webhook URL
                $webhookUrl = $this->generateWebhookUrl($account->id, $subscription->event_group);

                // Use FicApiService to renew the subscription
                $service = new FicApiService($account);
                $result = $service->createOrRenewSubscription($subscription->event_group, $webhookUrl);

                // Update subscription with new data
                $subscription->update([
                    'fic_subscription_id' => $result['id'] ?? $subscription->fic_subscription_id,
                    'webhook_secret' => $result['secret'] ?? $subscription->webhook_secret,
                    'expires_at' => $result['expires_at'] ?? $subscription->expires_at,
                    'is_active' => true,
                ]);

                $expiresAt = $result['expires_at'] ? $result['expires_at']->format('Y-m-d H:i:s') : 'N/A';
                $this->info("  ✓ Successfully renewed (expires: {$expiresAt})");

                Log::info('FIC Refresh Subscriptions: Subscription renewed', [
                    'subscription_id' => $subscription->id,
                    'account_id' => $account->id,
                    'event_group' => $subscription->event_group,
                    'new_expires_at' => $expiresAt,
                ]);

                $successCount++;

            } catch (\RuntimeException $e) {
                // Handle rate limiting and authentication errors
                $statusCode = $e->getCode();
                if ($statusCode === 429) {
                    $this->error("  ✗ Rate limit exceeded: {$e->getMessage()}");
                } elseif ($statusCode === 401) {
                    $this->error("  ✗ Authentication failed: {$e->getMessage()}");
                } else {
                    $this->error("  ✗ Error: {$e->getMessage()}");
                }

                Log::error('FIC Refresh Subscriptions: Failed to renew subscription', [
                    'subscription_id' => $subscription->id,
                    'account_id' => $subscription->fic_account_id,
                    'error' => $e->getMessage(),
                    'status_code' => $statusCode,
                ]);

                $errorCount++;
            } catch (\Exception $e) {
                $this->error("  ✗ Unexpected error: {$e->getMessage()}");
                Log::error('FIC Refresh Subscriptions: Unexpected error', [
                    'subscription_id' => $subscription->id,
                    'account_id' => $subscription->fic_account_id,
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
                $errorCount++;
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info("Summary: {$successCount} renewed, {$errorCount} failed");

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Generate webhook URL for a given account and event group.
     *
     * @param int $accountId
     * @param string $eventGroup
     * @return string
     */
    private function generateWebhookUrl(int $accountId, string $eventGroup): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        return "{$baseUrl}/api/webhooks/fic/{$accountId}/{$eventGroup}";
    }
}