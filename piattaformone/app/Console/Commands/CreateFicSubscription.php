<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to create a new FIC webhook subscription.
 *
 * This command creates a subscription for a specific event type
 * (e.g., 'it.fattureincloud.webhooks.entities.clients.create')
 * or event group (e.g., 'entity').
 */
class CreateFicSubscription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:create-subscription
                            {account_id : The FIC account ID}
                            {event_type : The event type (e.g., it.fattureincloud.webhooks.entities.clients.create) or event group (e.g., entity)}
                            {--webhook-url= : Custom webhook URL (optional, defaults to auto-generated)}
                            {--group= : Event group name for URL routing (optional, extracted from event_type if not provided)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new FIC webhook subscription for a specific event type';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = (int) $this->argument('account_id');
        $eventType = $this->argument('event_type');

        // Find the account
        $account = FicAccount::find($accountId);
        if (!$account) {
            $this->error("Account with ID {$accountId} not found.");
            return Command::FAILURE;
        }

        // Check if account has access token
        if (empty($account->access_token)) {
            $this->error("Account {$accountId} has no access token. Please connect the account first.");
            return Command::FAILURE;
        }

        // Determine event group for URL routing
        $eventGroup = $this->option('group');
        if (!$eventGroup) {
            // Extract group from event type if it's a full event name
            // e.g., 'it.fattureincloud.webhooks.entities.clients.create' -> 'entity'
            if (str_contains($eventType, '.')) {
                $parts = explode('.', $eventType);
                // Look for common patterns: entities -> entity, issued_documents -> issued_documents
                if (in_array('entities', $parts)) {
                    $eventGroup = 'entity';
                } elseif (in_array('issued_documents', $parts)) {
                    $eventGroup = 'issued_documents';
                } elseif (in_array('products', $parts)) {
                    $eventGroup = 'products';
                } elseif (in_array('receipts', $parts)) {
                    $eventGroup = 'receipts';
                } else {
                    // Default: use the first meaningful part after 'webhooks'
                    $webhookIndex = array_search('webhooks', $parts);
                    if ($webhookIndex !== false && isset($parts[$webhookIndex + 1])) {
                        $eventGroup = $parts[$webhookIndex + 1];
                    } else {
                        $eventGroup = 'default';
                    }
                }
            } else {
                // Simple event group name
                $eventGroup = $eventType;
            }
        }

        $this->info("Creating subscription for account ID: {$accountId}");
        $this->info("Event type: {$eventType}");
        $this->info("Event group (for routing): {$eventGroup}");
        $this->newLine();

        // Generate webhook URL
        $webhookUrl = $this->option('webhook-url');
        if (!$webhookUrl) {
            $baseUrl = rtrim(config('app.url'), '/');
            $webhookUrl = "{$baseUrl}/api/webhooks/fic/{$accountId}/{$eventGroup}";
        }

        $this->info("Webhook URL: {$webhookUrl}");
        $this->newLine();

        // Check if subscription already exists
        $existingSubscription = FicSubscription::where('fic_account_id', $accountId)
            ->where('event_group', $eventGroup)
            ->where('is_active', true)
            ->first();

        if ($existingSubscription) {
            if (!$this->confirm("A subscription already exists for event group '{$eventGroup}'. Do you want to renew it?", false)) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            // Create FicApiService instance
            $service = new FicApiService($account);

            // For specific event types (full event names), we need to use the new API format
            // Check if it's a full event name (contains dots)
            if (str_contains($eventType, '.')) {
                // Use the new method for specific event types
                $result = $service->createOrRenewSubscriptionForEventType($eventType, $webhookUrl, $eventGroup);
            } else {
                // Use existing method for simple event groups
                $result = $service->createOrRenewSubscription($eventType, $webhookUrl);
            }

            // Save or update subscription in database
            if ($existingSubscription) {
                $subscription = $existingSubscription;
                $subscription->update([
                    'fic_subscription_id' => $result['id'] ?? $subscription->fic_subscription_id,
                    'webhook_secret' => $result['secret'] ?? $subscription->webhook_secret,
                    'expires_at' => $result['expires_at'] ?? $subscription->expires_at,
                    'is_active' => true,
                ]);
                $this->info("  ✓ Subscription renewed successfully");
            } else {
                $subscription = FicSubscription::create([
                    'fic_account_id' => $accountId,
                    'fic_subscription_id' => $result['id'] ?? 'pending',
                    'event_group' => $eventGroup,
                    'webhook_secret' => $result['secret'] ?? null,
                    'expires_at' => $result['expires_at'] ?? null,
                    'is_active' => true,
                ]);
                $this->info("  ✓ Subscription created successfully");
            }

            $this->newLine();
            $this->info("Subscription Details:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Subscription ID', $subscription->id],
                    ['FIC Subscription ID', $subscription->fic_subscription_id],
                    ['Event Type', $eventType],
                    ['Event Group', $eventGroup],
                    ['Webhook URL', $webhookUrl],
                    ['Expires At', $subscription->expires_at ? $subscription->expires_at->format('Y-m-d H:i:s') : 'N/A'],
                    ['Status', $subscription->is_active ? 'Active' : 'Inactive'],
                ]
            );

            Log::info('FIC Create Subscription: Subscription created', [
                'subscription_id' => $subscription->id,
                'account_id' => $accountId,
                'event_type' => $eventType,
                'event_group' => $eventGroup,
            ]);

            $this->newLine();
            $this->info("Next steps:");
            $this->line("1. Fatture in Cloud will send a verification request to your webhook URL");
            $this->line("2. The webhook endpoint will automatically handle the verification");
            $this->line("3. Once verified, you will start receiving webhook notifications");

            return Command::SUCCESS;

        } catch (\RuntimeException $e) {
            $statusCode = $e->getCode();
            if ($statusCode === 429) {
                $this->error("  ✗ Rate limit exceeded: {$e->getMessage()}");
            } elseif ($statusCode === 401) {
                $this->error("  ✗ Authentication failed: {$e->getMessage()}");
                $this->warn("   The access token may be expired. Please refresh it.");
            } else {
                $this->error("  ✗ Error: {$e->getMessage()}");
            }

            Log::error('FIC Create Subscription: Failed to create subscription', [
                'account_id' => $accountId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
            ]);

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("  ✗ Unexpected error: {$e->getMessage()}");
            Log::error('FIC Create Subscription: Unexpected error', [
                'account_id' => $accountId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return Command::FAILURE;
        }
    }

}
