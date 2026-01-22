<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to sync FIC webhook subscriptions from API to local database.
 *
 * This command fetches all subscriptions from the FIC API and syncs them
 * to the local database, extracting the event_group from the webhook URL.
 */
class SyncFicSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:sync-subscriptions
                            {--account-id= : Filter by account ID}
                            {--dry-run : Show what would be synced without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync FIC webhook subscriptions from API to local database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account-id');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Get accounts to sync
        $accounts = $accountId 
            ? FicAccount::where('id', $accountId)->get()
            : FicAccount::all();

        if ($accounts->isEmpty()) {
            $this->error('No FIC accounts found.');
            return Command::FAILURE;
        }

        $totalSynced = 0;
        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($accounts as $account) {
            $this->info("Syncing subscriptions for account: {$account->name} (ID: {$account->id})");

            try {
                $apiService = new FicApiService($account);
                $subscriptions = $apiService->fetchSubscriptions();

                if (empty($subscriptions)) {
                    $this->warn("  No subscriptions found for this account.");
                    continue;
                }

                $this->info("  Found " . count($subscriptions) . " subscription(s) in FIC API");

                foreach ($subscriptions as $sub) {
                    $ficSubId = $sub['id'] ?? null;
                    $sink = $sub['sink'] ?? '';
                    $verified = $sub['verified'] ?? false;
                    $types = $sub['types'] ?? [];

                    if (!$ficSubId) {
                        $this->warn("  ⚠️  Skipping subscription without ID");
                        continue;
                    }

                    // Extract event_group from URL
                    // URL format: https://domain.com/api/webhooks/fic/{account_id}/{event_group}
                    $eventGroup = $this->extractEventGroupFromUrl($sink, $account->id);

                    if (!$eventGroup) {
                        $this->warn("  ⚠️  Could not extract event_group from URL: {$sink}");
                        // Try to extract from first event type as fallback
                        if (!empty($types)) {
                            $eventGroup = $this->extractEventGroupFromEventType($types[0]);
                            $this->info("  📝 Using event_group from event type: {$eventGroup}");
                        } else {
                            $eventGroup = 'default';
                            $this->info("  📝 Using default event_group: {$eventGroup}");
                        }
                    }

                    $this->line("  Subscription: {$ficSubId}");
                    $this->line("    Event Group: {$eventGroup}");
                    $this->line("    URL: {$sink}");
                    $this->line("    Verified: " . ($verified ? '✓' : '✗'));
                    $this->line("    Event Types: " . count($types));

                    if ($dryRun) {
                        // Check if would be created or updated
                        $existing = FicSubscription::where('fic_account_id', $account->id)
                            ->where('event_group', $eventGroup)
                            ->first();

                        if ($existing) {
                            $this->line("    [DRY RUN] Would update existing subscription ID: {$existing->id}");
                        } else {
                            $this->line("    [DRY RUN] Would create new subscription");
                        }
                    } else {
                        // Sync to database
                        $subscription = FicSubscription::updateOrCreate(
                            [
                                'fic_account_id' => $account->id,
                                'event_group' => $eventGroup,
                            ],
                            [
                                'fic_subscription_id' => $ficSubId,
                                'is_active' => $verified, // Only active if verified
                                'webhook_secret' => null, // API doesn't return secret
                                'expires_at' => null, // API doesn't return expiration
                            ]
                        );

                        if ($subscription->wasRecentlyCreated) {
                            $totalCreated++;
                            $this->line("    ✅ Created subscription in database (ID: {$subscription->id})");
                        } else {
                            $totalUpdated++;
                            $this->line("    ✅ Updated subscription in database (ID: {$subscription->id})");
                        }
                    }

                    $this->newLine();
                }

                $totalSynced += count($subscriptions);

            } catch (\Exception $e) {
                $this->error("  Error syncing subscriptions: " . $e->getMessage());
                Log::error('FIC Sync Subscriptions: Error', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("Total subscriptions synced: {$totalSynced}");

        if (!$dryRun) {
            $this->line("Created: {$totalCreated}");
            $this->line("Updated: {$totalUpdated}");
        }

        return Command::SUCCESS;
    }

    /**
     * Extract event_group from webhook URL.
     *
     * URL format: https://domain.com/api/webhooks/fic/{account_id}/{event_group}
     *
     * @param string $url The webhook URL
     * @param int $accountId The account ID to match
     * @return string|null The event_group or null if not found
     */
    private function extractEventGroupFromUrl(string $url, int $accountId): ?string
    {
        // Pattern: /api/webhooks/fic/{account_id}/{event_group}
        $pattern = '/\/api\/webhooks\/fic\/' . preg_quote($accountId, '/') . '\/([a-z_]+)/';
        
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1] ?? null;
        }

        return null;
    }

    /**
     * Extract event_group from event type.
     *
     * @param string $eventType The event type (e.g., it.fattureincloud.webhooks.entities.clients.create)
     * @return string The event_group
     */
    private function extractEventGroupFromEventType(string $eventType): string
    {
        $parts = explode('.', $eventType);
        
        // Look for common patterns
        if (in_array('entities', $parts)) {
            return 'entity';
        }
        
        if (in_array('issued_documents', $parts)) {
            return 'issued_documents';
        }
        
        if (in_array('received_documents', $parts)) {
            return 'received_documents';
        }

        // Default fallback
        $webhookIndex = array_search('webhooks', $parts);
        if ($webhookIndex !== false && isset($parts[$webhookIndex + 1])) {
            return $parts[$webhookIndex + 1];
        }

        return 'default';
    }
}
