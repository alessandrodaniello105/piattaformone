<?php

namespace App\Jobs;

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
     * This method can be extended to handle different event types.
     *
     * @param string $eventName The event name (e.g., 'entity.create', 'issued_document.create')
     * @return void
     */
    private function processEvent(string $eventName): void
    {
        // Extract event type and action from event name
        // Examples: 'entity.create' -> type: 'entity', action: 'create'
        $parts = explode('.', $eventName, 2);
        $eventType = $parts[0] ?? 'unknown';
        $action = $parts[1] ?? 'unknown';

        switch ($eventType) {
            case 'entity':
                $this->handleEntityEvent($action);
                break;

            case 'issued_documents':
            case 'issued_document':
                $this->handleIssuedDocumentEvent($action);
                break;

            default:
                Log::debug('FIC Webhook: Unhandled event type', [
                    'event' => $eventName,
                    'event_type' => $eventType,
                    'action' => $action,
                    'account_id' => $this->accountId,
                ]);
        }
    }

    /**
     * Handle entity-related events.
     *
     * @param string $action The action (create, update, delete, etc.)
     * @return void
     */
    private function handleEntityEvent(string $action): void
    {
        Log::info('FIC Webhook: Processing entity event', [
            'action' => $action,
            'account_id' => $this->accountId,
        ]);

        // TODO: Implement entity event processing logic
        // Examples:
        // - create: Create new customer/client in local system
        // - update: Update existing customer/client
        // - delete: Mark customer/client as deleted
    }

    /**
     * Handle issued document events.
     *
     * @param string $action The action (create, update, delete, etc.)
     * @return void
     */
    private function handleIssuedDocumentEvent(string $action): void
    {
        Log::info('FIC Webhook: Processing issued document event', [
            'action' => $action,
            'account_id' => $this->accountId,
        ]);

        // TODO: Implement issued document event processing logic
        // Examples:
        // - create: Create new invoice in local system
        // - update: Update existing invoice
        // - delete: Mark invoice as deleted
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