<?php

namespace App\Jobs;

use App\Models\FicAccount;
use App\Models\FicEvent;
use App\Jobs\SyncFicResourceJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for processing Fatture in Cloud webhook events asynchronously.
 *
 * This job processes webhook notifications from FIC in the background
 * with structured logging and automatic retry on failure.
 */
class ProcessFicWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param array<string, mixed> $payload The webhook payload
     * @param int $accountId The FIC account ID
     * @param string $eventGroup The event group (e.g., 'entity', 'issued_documents')
     */
    public function __construct(
        public array $payload,
        public int $accountId,
        public string $eventGroup
    ) {
        // Set queue connection to redis
        $this->onConnection('redis');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $eventName = $this->payload['event'] ?? 'unknown';

        // Log structured information about the event
        Log::info('FIC Webhook: Processing event', [
            'event' => $eventName,
            'account_id' => $this->accountId,
            'event_group' => $this->eventGroup,
            'payload_keys' => array_keys($this->payload),
        ]);

        try {
            // Extract event-specific data for conditional logging
            $this->logEventData($eventName);

            // Process the event based on its type
            $this->processEvent($eventName);

            Log::info('FIC Webhook: Event processed successfully', [
                'event' => $eventName,
                'account_id' => $this->accountId,
                'event_group' => $this->eventGroup,
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Webhook: Error processing event', [
                'event' => $eventName,
                'account_id' => $this->accountId,
                'event_group' => $this->eventGroup,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Log event-specific data conditionally.
     *
     * For entity.create events, logs the new client/entity data.
     *
     * @param string $eventName The event name
     * @return void
     */
    private function logEventData(string $eventName): void
    {
        // Conditional logging: if entity.create, log new client data
        if ($eventName === 'entity.create') {
            $entityData = $this->payload['data'] ?? $this->payload['entity'] ?? null;

            if ($entityData) {
                Log::info('FIC Webhook: New entity created', [
                    'account_id' => $this->accountId,
                    'event_group' => $this->eventGroup,
                    'event' => $eventName,
                    'entity_type' => $entityData['type'] ?? 'unknown',
                    'entity_id' => $entityData['id'] ?? null,
                    'entity_name' => $entityData['name'] ?? $entityData['code'] ?? null,
                    // Log only essential data to avoid sensitive information
                    'entity_data' => $this->sanitizeEntityData($entityData),
                ]);
            } else {
                Log::warning('FIC Webhook: entity.create event without entity data', [
                    'account_id' => $this->accountId,
                    'event_group' => $this->eventGroup,
                    'payload_keys' => array_keys($this->payload),
                ]);
            }
        }
    }

    /**
     * Sanitize entity data before logging.
     *
     * Removes sensitive fields like email, phone, tax code, etc.
     *
     * @param array<string, mixed> $entityData
     * @return array<string, mixed>
     */
    private function sanitizeEntityData(array $entityData): array
    {
        $sensitiveFields = [
            'email',
            'phone',
            'tax_code',
            'vat_number',
            'bank_iban',
            'bank_name',
            'notes',
        ];

        $sanitized = $entityData;

        foreach ($sensitiveFields as $field) {
            if (isset($sanitized[$field])) {
                $sanitized[$field] = '[REDACTED]';
            }
        }

        return $sanitized;
    }

    /**
     * Process the webhook event based on its type.
     *
     * Maps CloudEvents event types to appropriate handlers using SyncFicResourceJob.
     *
     * @param string $eventName The CloudEvents event name (e.g., 'it.fattureincloud.webhooks.entities.clients.create')
     * @return void
     */
    private function processEvent(string $eventName): void
    {
        // Extract resource type and action from event name
        $mapping = $this->extractResourceMapping($eventName);

        if (! $mapping) {
            Log::debug('FIC Webhook: Unhandled event type', [
                'event' => $eventName,
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $ids = $this->payload['data']['ids'] ?? [];
        $occurredAt = $this->payload['occurred_at'] ?? now();

        if (empty($ids)) {
            Log::warning('FIC Webhook: Event with empty IDs array', [
                'event' => $eventName,
                'account_id' => $this->accountId,
                'resource_type' => $mapping['resource_type'],
                'action' => $mapping['action'],
            ]);
            return;
        }

        Log::info('FIC Webhook: Processing event', [
            'event' => $eventName,
            'account_id' => $this->accountId,
            'resource_type' => $mapping['resource_type'],
            'action' => $mapping['action'],
            'ids_count' => count($ids),
            'ids' => $ids,
        ]);

        foreach ($ids as $ficId) {
            try {
                // Update event status from pending to processed
                $event = FicEvent::where('fic_account_id', $this->accountId)
                    ->where('event_type', $eventName)
                    ->where('resource_type', $mapping['resource_type'])
                    ->where('fic_resource_id', (int) $ficId)
                    ->where('status', 'pending')
                    ->first();

                if ($event) {
                    $event->update(['status' => 'processed']);
                    Log::debug('FIC Webhook: Event status updated to processed', [
                        'account_id' => $this->accountId,
                        'resource_type' => $mapping['resource_type'],
                        'fic_id' => $ficId,
                        'event_id' => $event->id,
                    ]);
                } else {
                    // Fallback: create event if not found (shouldn't happen, but handle gracefully)
                    Log::warning('FIC Webhook: Pending event not found, creating new one', [
                        'account_id' => $this->accountId,
                        'resource_type' => $mapping['resource_type'],
                        'fic_id' => $ficId,
                        'event_type' => $eventName,
                    ]);
                    FicEvent::create([
                        'fic_account_id' => $this->accountId,
                        'event_type' => $eventName,
                        'resource_type' => $mapping['resource_type'],
                        'fic_resource_id' => (int) $ficId,
                        'occurred_at' => $occurredAt ? new \Carbon\Carbon($occurredAt) : now(),
                        'payload' => $this->payload,
                        'status' => 'processed',
                    ]);
                }

                // Dispatch generic sync job
                SyncFicResourceJob::dispatch(
                    $mapping['resource_type'],
                    (int) $ficId,
                    $this->accountId,
                    $mapping['action']
                )->onConnection('redis');

                Log::debug('FIC Webhook: Sync job dispatched', [
                    'account_id' => $this->accountId,
                    'resource_type' => $mapping['resource_type'],
                    'fic_id' => $ficId,
                    'action' => $mapping['action'],
                ]);
            } catch (\Exception $e) {
                // Update event status to failed if it exists
                try {
                    $event = FicEvent::where('fic_account_id', $this->accountId)
                        ->where('event_type', $eventName)
                        ->where('resource_type', $mapping['resource_type'])
                        ->where('fic_resource_id', (int) $ficId)
                        ->where('status', 'pending')
                        ->first();

                    if ($event) {
                        $event->update(['status' => 'failed']);
                    }
                } catch (\Exception $updateException) {
                    // Ignore update errors
                }

                // Log error but continue processing other IDs
                Log::error('FIC Webhook: Error dispatching sync job', [
                    'account_id' => $this->accountId,
                    'resource_type' => $mapping['resource_type'],
                    'fic_id' => $ficId,
                    'action' => $mapping['action'],
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Extract resource type and action from CloudEvents event name.
     *
     * @param string $eventName The CloudEvents event name
     * @return array{resource_type: string, action: string}|null Returns mapping or null if event is not supported
     */
    private function extractResourceMapping(string $eventName): ?array
    {
        // Map CloudEvents event types to resource types and actions
        // Format: it.fattureincloud.webhooks.{category}.{resource}.{action}

        // Clients: entities.clients.create/update/delete
        if (str_contains($eventName, 'entities.clients.create')) {
            return ['resource_type' => 'client', 'action' => 'created'];
        }
        if (str_contains($eventName, 'entities.clients.update')) {
            return ['resource_type' => 'client', 'action' => 'updated'];
        }
        if (str_contains($eventName, 'entities.clients.delete')) {
            return ['resource_type' => 'client', 'action' => 'deleted'];
        }

        // Suppliers: entities.suppliers.create/update/delete
        if (str_contains($eventName, 'entities.suppliers.create')) {
            return ['resource_type' => 'supplier', 'action' => 'created'];
        }
        if (str_contains($eventName, 'entities.suppliers.update')) {
            return ['resource_type' => 'supplier', 'action' => 'updated'];
        }
        if (str_contains($eventName, 'entities.suppliers.delete')) {
            return ['resource_type' => 'supplier', 'action' => 'deleted'];
        }

        // Invoices: issued_documents.invoices.create/update/delete
        if (str_contains($eventName, 'issued_documents.invoices.create')) {
            return ['resource_type' => 'invoice', 'action' => 'created'];
        }
        if (str_contains($eventName, 'issued_documents.invoices.update')) {
            return ['resource_type' => 'invoice', 'action' => 'updated'];
        }
        if (str_contains($eventName, 'issued_documents.invoices.delete')) {
            return ['resource_type' => 'invoice', 'action' => 'deleted'];
        }

        // Quotes: issued_documents.quotes.create/update/delete
        if (str_contains($eventName, 'issued_documents.quotes.create')) {
            return ['resource_type' => 'quote', 'action' => 'created'];
        }
        if (str_contains($eventName, 'issued_documents.quotes.update')) {
            return ['resource_type' => 'quote', 'action' => 'updated'];
        }
        if (str_contains($eventName, 'issued_documents.quotes.delete')) {
            return ['resource_type' => 'quote', 'action' => 'deleted'];
        }

        // Fallback to old format for backward compatibility
        $parts = explode('.', $eventName, 2);
        $eventType = $parts[0] ?? 'unknown';
        $action = $parts[1] ?? 'unknown';

        if ($eventType === 'entity' && $action === 'create') {
            // Try to determine if it's client or supplier from payload
            $entityData = $this->payload['data'] ?? $this->payload['entity'] ?? null;
            $entityType = $entityData['type'] ?? 'client'; // Default to client

            if ($entityType === 'supplier') {
                return ['resource_type' => 'supplier', 'action' => 'created'];
            }

            return ['resource_type' => 'client', 'action' => 'created'];
        }

        if (($eventType === 'issued_documents' || $eventType === 'issued_document') && $action === 'create') {
            // Try to determine document type from event name or payload
            if (str_contains($eventName, 'quote')) {
                return ['resource_type' => 'quote', 'action' => 'created'];
            }
            if (str_contains($eventName, 'invoice')) {
                return ['resource_type' => 'invoice', 'action' => 'created'];
            }
        }

        return null;
    }


    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FIC Webhook: Job failed after all retries', [
            'event' => $this->payload['event'] ?? 'unknown',
            'account_id' => $this->accountId,
            'event_group' => $this->eventGroup,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);
    }
}