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
 * to the local database.
 *
 * Important: According to FIC documentation, Group Types are converted to
 * Event Types when creating a subscription. GET requests always return
 * Event Types, not the original Group Types. Therefore, we extract the
 * event_group from the Event Types returned by the API (more reliable)
 * rather than from the URL, which may contain outdated Group Type information.
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
                    $this->warn('  No subscriptions found for this account.');

                    continue;
                }

                $this->info('  Found '.count($subscriptions).' subscription(s) in FIC API');

                foreach ($subscriptions as $sub) {
                    $ficSubId = $sub['id'] ?? null;
                    $sink = $sub['sink'] ?? '';
                    $verified = $sub['verified'] ?? false;
                    $types = $sub['types'] ?? [];

                    if (! $ficSubId) {
                        $this->warn('  ⚠️  Skipping subscription without ID');

                        continue;
                    }

                    // Extract event_group from URL
                    // URL format: https://domain.com/api/webhooks/fic/{account_id}/{event_group}
                    // Note: URL may contain Group Type (e.g., "issued_documents") if subscription
                    // was created with Group Types, but FIC converts them to Event Types.
                    $eventGroupFromUrl = $this->extractEventGroupFromUrl($sink, $account->id);

                    // Extract event_group from event types (MORE RELIABLE)
                    // According to FIC docs: "Group Types will be converted to the Event Types
                    // while creating the subscription, so the GET requests will return the
                    // Event Types and not the original Group Types."
                    // Therefore, Event Types are the source of truth.
                    $eventGroupFromTypes = ! empty($types)
                        ? $this->extractEventGroupFromEventType($types[0])
                        : null;

                    // Determine which event_group to use
                    // Always prefer event_group from Event Types (source of truth from FIC API)
                    if ($eventGroupFromTypes) {
                        $eventGroup = $eventGroupFromTypes;

                        // Check for mismatch between URL and event types
                        // This can happen if:
                        // 1. Subscription was created with wrong Group Type
                        // 2. URL was manually set incorrectly
                        // 3. Subscription was created with Group Type but FIC converted it
                        if ($eventGroupFromUrl && $eventGroupFromUrl !== $eventGroupFromTypes) {
                            $this->warn("  ⚠️  DISCREPANZA: URL contiene '{$eventGroupFromUrl}' ma event type indica '{$eventGroupFromTypes}'");
                            $this->warn("     URL: {$sink}");
                            $this->warn("     Event Type: {$types[0]}");
                            $this->warn("     Usando event_group dall'event type (fonte di verità da FIC API): {$eventGroupFromTypes}");
                            $this->warn("     Nota: I Group Types vengono convertiti in Event Types da FIC, quindi gli Event Types sono più affidabili.");
                        }
                    } elseif ($eventGroupFromUrl) {
                        // Fallback: use URL if no event types available
                        $eventGroup = $eventGroupFromUrl;
                        $this->info("  📝 Using event_group from URL (no event types available): {$eventGroup}");
                    } else {
                        $eventGroup = 'default';
                        $this->warn("  ⚠️  Could not extract event_group from URL or event types, using default: {$eventGroup}");
                    }

                    $this->line("  Subscription: {$ficSubId}");
                    $this->line("    Event Group: {$eventGroup}");
                    $this->line("    URL: {$sink}");
                    $this->line('    Verified: '.($verified ? '✓' : '✗'));
                    $this->line('    Event Types: '.count($types));

                    if ($dryRun) {
                        // Check if would be created or updated
                        // First check by fic_subscription_id (unique)
                        $existing = FicSubscription::where('fic_subscription_id', $ficSubId)->first();

                        if (! $existing) {
                            // Fallback: check by account_id + event_group
                            $existing = FicSubscription::where('fic_account_id', $account->id)
                                ->where('event_group', $eventGroup)
                                ->first();
                        }

                        if ($existing) {
                            $this->line("    [DRY RUN] Would update existing subscription ID: {$existing->id}");
                            if ($existing->event_group !== $eventGroup) {
                                $this->line("    [DRY RUN] Would update event_group from '{$existing->event_group}' to '{$eventGroup}'");
                            }
                        } else {
                            $this->line('    [DRY RUN] Would create new subscription');
                        }
                    } else {
                        // Sync to database
                        // First try to find by fic_subscription_id (unique constraint)
                        $existing = FicSubscription::where('fic_subscription_id', $ficSubId)->first();

                        if ($existing) {
                            // Update existing subscription (may need to update event_group if there was a mismatch)
                            $oldEventGroup = $existing->event_group;
                            $existing->update([
                                'fic_account_id' => $account->id,
                                'event_group' => $eventGroup,
                                'is_active' => $verified,
                                'webhook_secret' => null, // API doesn't return secret
                                'expires_at' => null, // API doesn't return expiration
                            ]);
                            $subscription = $existing;

                            $totalUpdated++;
                            if ($oldEventGroup !== $eventGroup) {
                                $this->line("    ✅ Updated subscription in database (ID: {$subscription->id}) - event_group changed from '{$oldEventGroup}' to '{$eventGroup}'");
                            } else {
                                $this->line("    ✅ Updated subscription in database (ID: {$subscription->id})");
                            }
                        } else {
                            // Create new subscription - always use fic_subscription_id as unique key
                            // Multiple subscriptions can have the same event_group, so we don't use
                            // updateOrCreate with event_group to avoid overwriting existing subscriptions
                            $subscription = FicSubscription::create([
                                'fic_account_id' => $account->id,
                                'fic_subscription_id' => $ficSubId,
                                'event_group' => $eventGroup,
                                'is_active' => $verified,
                                'webhook_secret' => null,
                                'expires_at' => null,
                            ]);

                            $totalCreated++;
                            $this->line("    ✅ Created subscription in database (ID: {$subscription->id})");
                        }
                    }

                    $this->newLine();
                }

                $totalSynced += count($subscriptions);

            } catch (\Exception $e) {
                $this->error('  Error syncing subscriptions: '.$e->getMessage());
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

        if (! $dryRun) {
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
     * @param  string  $url  The webhook URL
     * @param  int  $accountId  The account ID to match
     * @return string|null The event_group or null if not found
     */
    private function extractEventGroupFromUrl(string $url, int $accountId): ?string
    {
        // Pattern: /api/webhooks/fic/{account_id}/{event_group}
        $pattern = '/\/api\/webhooks\/fic\/'.preg_quote($accountId, '/').'\/([a-z_]+)/';

        if (preg_match($pattern, $url, $matches)) {
            return $matches[1] ?? null;
        }

        return null;
    }

    /**
     * Extract event_group from event type.
     *
     * This extracts the event_group from Event Types returned by FIC API.
     * Note: FIC converts Group Types to Event Types when creating subscriptions,
     * so GET requests always return Event Types. This makes Event Types the
     * reliable source of truth for determining the correct event_group.
     *
     * @param  string  $eventType  The event type (e.g., it.fattureincloud.webhooks.entities.clients.create)
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
