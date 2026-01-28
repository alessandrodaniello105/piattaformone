<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to fix FIC subscriptions with incorrect URLs.
 *
 * This command finds subscriptions where the URL contains a different account_id
 * than the subscription's actual account_id, deletes them from FIC API, and
 * recreates them with the correct URL.
 */
class FixFicSubscriptionsUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:fix-subscription-urls
                            {--account-id= : Fix subscriptions for specific account ID only}
                            {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix FIC subscriptions with incorrect URLs by deleting and recreating them';

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

        // Get accounts to check
        $accounts = $accountId
            ? FicAccount::where('id', $accountId)->get()
            : FicAccount::where('status', 'active')->get();

        if ($accounts->isEmpty()) {
            $this->error('No FIC accounts found.');

            return Command::FAILURE;
        }

        $totalFixed = 0;
        $totalDeleted = 0;
        $totalRecreated = 0;
        $totalErrors = 0;

        foreach ($accounts as $account) {
            $this->info("Checking account: {$account->name} (ID: {$account->id})");

            try {
                $apiService = new FicApiService($account);
                $subscriptions = $apiService->fetchSubscriptions();

                if (empty($subscriptions)) {
                    $this->warn('  No subscriptions found for this account.');

                    continue;
                }

                $this->info('  Found '.count($subscriptions).' subscription(s) in FIC API');

                // Process each subscription individually
                foreach ($subscriptions as $sub) {
                    $ficSubId = $sub['id'] ?? null;
                    $sink = $sub['sink'] ?? '';
                    $types = $sub['types'] ?? [];

                    if (! $ficSubId) {
                        $this->warn('  ⚠️  Skipping subscription without ID');

                        continue;
                    }

                    // Extract account_id and event_group from URL
                    $expectedPattern = '/\/api\/webhooks\/fic\/(\d+)\/([a-z_]+)/';
                    $urlMatches = preg_match($expectedPattern, $sink, $matches);
                    $extractedAccountId = $matches[1] ?? null;
                    $extractedGroup = $matches[2] ?? null;

                    // Extract event_group from types if not available from URL
                    $eventGroup = $extractedGroup;
                    if (! $eventGroup && ! empty($types)) {
                        $eventGroup = $this->extractEventGroupFromEventType($types[0]);
                    }
                    if (! $eventGroup) {
                        $eventGroup = 'default';
                    }

                    // Check if URL account_id matches actual account_id
                    if ($extractedAccountId && (int) $extractedAccountId !== $account->id) {
                        $this->warn('  ⚠️  Found subscription with incorrect URL:');
                        $this->line("     Subscription ID: {$ficSubId}");
                        $this->line("     Current URL: {$sink}");
                        $this->line("     URL account ID: {$extractedAccountId}");
                        $this->line("     Actual account ID: {$account->id}");
                        $this->line("     Event Group: {$eventGroup}");
                        $this->line('     Event Types: '.count($types));

                        // Generate correct URL
                        $baseUrl = rtrim(config('app.url'), '/');
                        $correctUrl = "{$baseUrl}/api/webhooks/fic/{$account->id}/{$eventGroup}";

                        $this->line("     Correct URL: {$correctUrl}");

                        if ($dryRun) {
                            $this->line('     [DRY RUN] Would delete and recreate this subscription');
                            $totalFixed++;
                        } else {
                            try {
                                // Delete subscription from FIC API
                                $this->line('     Deleting subscription from FIC API...');
                                $apiService->deleteSubscription($ficSubId);
                                $this->line('     ✓ Deleted from FIC API');

                                // Delete from local database
                                $localSub = FicSubscription::where('fic_subscription_id', $ficSubId)->first();
                                if ($localSub) {
                                    $localSub->delete();
                                    $this->line('     ✓ Deleted from local database');
                                }

                                $totalDeleted++;

                                // Recreate subscription with correct URL and same event types
                                // Always use POST to create a new subscription (don't check DB for existing)
                                $this->line('     Recreating subscription with correct URL...');
                                $newFicSubId = $this->createSubscriptionDirectly($apiService, $account, $types, $correctUrl);

                                if ($newFicSubId) {
                                    // Check if there's already a subscription for this event_group and fic_subscription_id
                                    // If yes, update it; if no, create new one
                                    $existingSub = FicSubscription::where('fic_account_id', $account->id)
                                        ->where('event_group', $eventGroup)
                                        ->where('fic_subscription_id', $newFicSubId)
                                        ->first();

                                    if ($existingSub) {
                                        // Update existing subscription
                                        $existingSub->update([
                                            'is_active' => true,
                                            'webhook_secret' => null,
                                            'expires_at' => null,
                                        ]);
                                        $this->line("     ✓ Updated existing subscription in DB (ID: {$existingSub->id})");
                                    } else {
                                        // Check if there's another subscription for the same event_group
                                        // If yes, we'll create a new record (multiple subscriptions per event_group are allowed)
                                        FicSubscription::create([
                                            'fic_account_id' => $account->id,
                                            'fic_subscription_id' => $newFicSubId,
                                            'event_group' => $eventGroup,
                                            'is_active' => true,
                                            'webhook_secret' => null,
                                            'expires_at' => null,
                                        ]);
                                        $this->line('     ✓ Created new subscription in DB');
                                    }

                                    $this->line("     ✓ Recreated with ID: {$newFicSubId}");
                                    $totalRecreated++;
                                    $totalFixed++;
                                } else {
                                    $this->error('     ✗ Failed to recreate subscription');
                                    $totalErrors++;
                                }
                            } catch (\Exception $e) {
                                $this->error('     ✗ Error: '.$e->getMessage());
                                Log::error('FIC Fix Subscriptions: Error fixing subscription', [
                                    'account_id' => $account->id,
                                    'subscription_id' => $ficSubId,
                                    'error' => $e->getMessage(),
                                ]);
                                $totalErrors++;
                            }
                        }

                        $this->newLine();
                    } else {
                        // Subscription has correct URL - sync to database if missing
                        $this->line("  ✓ Subscription {$ficSubId} has correct URL");

                        if (! $dryRun) {
                            // Ensure subscription exists in local database
                            $localSub = FicSubscription::where('fic_subscription_id', $ficSubId)->first();
                            if (! $localSub) {
                                // Extract event_group from types if not available from URL
                                $eventGroup = $extractedGroup;
                                if (! $eventGroup && ! empty($types)) {
                                    $eventGroup = $this->extractEventGroupFromEventType($types[0]);
                                }
                                if (! $eventGroup) {
                                    $eventGroup = 'default';
                                }

                                FicSubscription::create([
                                    'fic_account_id' => $account->id,
                                    'fic_subscription_id' => $ficSubId,
                                    'event_group' => $eventGroup,
                                    'is_active' => true,
                                    'webhook_secret' => null,
                                    'expires_at' => null,
                                ]);
                                $this->line('     ✓ Synced to local database');
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error('  Error processing account: '.$e->getMessage());
                Log::error('FIC Fix Subscriptions: Error processing account', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                $totalErrors++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('=== Summary ===');
        $this->line("Subscriptions fixed: {$totalFixed}");

        if (! $dryRun) {
            $this->line("Deleted: {$totalDeleted}");
            $this->line("Recreated: {$totalRecreated}");
            if ($totalErrors > 0) {
                $this->error("Errors: {$totalErrors}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Create a subscription directly via FIC API (always POST, no DB check).
     *
     * @return string|null The new subscription ID
     */
    private function createSubscriptionDirectly(
        FicApiService $apiService,
        FicAccount $account,
        array $eventTypes,
        string $webhookUrl
    ): ?string {
        try {
            // Use reflection to access private methods or call API directly
            // Since we need to bypass the DB check, we'll use the SDK directly
            $reflection = new \ReflectionClass($apiService);
            $initializeSdkMethod = $reflection->getMethod('initializeSdk');
            $initializeSdkMethod->setAccessible(true);
            $initializeSdkMethod->invoke($apiService);

            // Get HTTP client and config
            $httpClientProperty = $reflection->getProperty('httpClient');
            $httpClientProperty->setAccessible(true);
            $httpClient = $httpClientProperty->getValue($apiService);

            $baseUrl = 'https://api-v2.fattureincloud.it';
            $companyId = $account->company_id;
            $accessToken = $account->access_token;

            $url = "{$baseUrl}/c/{$companyId}/subscriptions";

            $payload = [
                'data' => [
                    'sink' => $webhookUrl,
                    'types' => $eventTypes,
                    'verification_method' => 'header',
                    'config' => [
                        'mapping' => 'binary',
                    ],
                ],
            ];

            $response = $httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode}: ".($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            $data = $responseData['data'] ?? $responseData;

            return $data['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('FIC Fix Subscriptions: Error creating subscription directly', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract event_group from event type.
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
