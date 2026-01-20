<?php

namespace App\Jobs;

use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicEvent;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Services\FicApiService;
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
     * Maps CloudEvents event types to appropriate handlers.
     *
     * @param string $eventName The CloudEvents event name (e.g., 'it.fattureincloud.webhooks.entities.clients.create')
     * @return void
     */
    private function processEvent(string $eventName): void
    {
        // Map CloudEvents event types to handlers
        // Examples:
        // - it.fattureincloud.webhooks.entities.clients.create -> handle clients.create
        // - it.fattureincloud.webhooks.issued_documents.quotes.create -> handle quotes.create
        // - it.fattureincloud.webhooks.issued_documents.invoices.create -> handle invoices.create

        if (str_contains($eventName, 'entities.clients.create')) {
            $this->handleClientsCreate();
        } elseif (str_contains($eventName, 'issued_documents.quotes.create')) {
            $this->handleQuotesCreate();
        } elseif (str_contains($eventName, 'issued_documents.invoices.create')) {
            $this->handleInvoicesCreate();
        } else {
            // Fallback to old format for backward compatibility
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
    }

    /**
     * Handle clients.create events.
     *
     * Fetches client details from FIC API and upserts into fic_clients table.
     *
     * @return void
     */
    private function handleClientsCreate(): void
    {
        $ids = $this->payload['data']['ids'] ?? [];
        $occurredAt = $this->payload['occurred_at'] ?? now();

        Log::info('FIC Webhook: Processing clients.create event', [
            'account_id' => $this->accountId,
            'ids_count' => count($ids),
            'ids' => $ids,
        ]);

        if (empty($ids)) {
            Log::warning('FIC Webhook: clients.create event with empty IDs array', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $account = FicAccount::find($this->accountId);
        if (!$account) {
            throw new \RuntimeException("FIC account {$this->accountId} not found");
        }

        $apiService = new FicApiService($account);

        foreach ($ids as $clientId) {
            try {
                // Fetch client details from FIC API
                $clientData = $apiService->fetchClientById((int) $clientId);

                // Upsert client into database
                FicClient::updateOrCreate(
                    [
                        'fic_account_id' => $this->accountId,
                        'fic_client_id' => $clientData['id'],
                    ],
                    [
                        'name' => $clientData['name'],
                        'code' => $clientData['code'],
                        'fic_created_at' => $clientData['fic_created_at'],
                        'fic_updated_at' => $clientData['fic_updated_at'],
                        'raw' => $clientData['raw'],
                    ]
                );

                // Create event log entry
                FicEvent::create([
                    'fic_account_id' => $this->accountId,
                    'event_type' => $this->payload['event'] ?? 'it.fattureincloud.webhooks.entities.clients.create',
                    'resource_type' => 'client',
                    'fic_resource_id' => $clientData['id'],
                    'occurred_at' => $occurredAt ? new \Carbon\Carbon($occurredAt) : now(),
                    'payload' => $this->payload,
                ]);

                Log::info('FIC Webhook: Client synced successfully', [
                    'account_id' => $this->accountId,
                    'client_id' => $clientData['id'],
                ]);
            } catch (\Exception $e) {
                // Log error but continue processing other IDs
                Log::error('FIC Webhook: Error processing client', [
                    'account_id' => $this->accountId,
                    'client_id' => $clientId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Handle quotes.create events.
     *
     * Fetches quote details from FIC API and upserts into fic_quotes table.
     *
     * @return void
     */
    private function handleQuotesCreate(): void
    {
        $ids = $this->payload['data']['ids'] ?? [];
        $occurredAt = $this->payload['occurred_at'] ?? now();

        Log::info('FIC Webhook: Processing quotes.create event', [
            'account_id' => $this->accountId,
            'ids_count' => count($ids),
            'ids' => $ids,
        ]);

        if (empty($ids)) {
            Log::warning('FIC Webhook: quotes.create event with empty IDs array', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $account = FicAccount::find($this->accountId);
        if (!$account) {
            throw new \RuntimeException("FIC account {$this->accountId} not found");
        }

        $apiService = new FicApiService($account);

        foreach ($ids as $quoteId) {
            try {
                // Fetch quote details from FIC API
                $quoteData = $apiService->fetchIssuedQuoteById((int) $quoteId);

                // Upsert quote into database
                FicQuote::updateOrCreate(
                    [
                        'fic_account_id' => $this->accountId,
                        'fic_quote_id' => $quoteData['id'],
                    ],
                    [
                        'number' => $quoteData['number'],
                        'status' => $quoteData['status'],
                        'total_gross' => $quoteData['total_gross'],
                        'fic_date' => $quoteData['fic_date'],
                        'fic_created_at' => $quoteData['fic_created_at'],
                        'raw' => $quoteData['raw'],
                    ]
                );

                // Create event log entry
                FicEvent::create([
                    'fic_account_id' => $this->accountId,
                    'event_type' => $this->payload['event'] ?? 'it.fattureincloud.webhooks.issued_documents.quotes.create',
                    'resource_type' => 'quote',
                    'fic_resource_id' => $quoteData['id'],
                    'occurred_at' => $occurredAt ? new \Carbon\Carbon($occurredAt) : now(),
                    'payload' => $this->payload,
                ]);

                Log::info('FIC Webhook: Quote synced successfully', [
                    'account_id' => $this->accountId,
                    'quote_id' => $quoteData['id'],
                ]);
            } catch (\Exception $e) {
                // Log error but continue processing other IDs
                Log::error('FIC Webhook: Error processing quote', [
                    'account_id' => $this->accountId,
                    'quote_id' => $quoteId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Handle invoices.create events.
     *
     * Fetches invoice details from FIC API and upserts into fic_invoices table.
     *
     * @return void
     */
    private function handleInvoicesCreate(): void
    {
        $ids = $this->payload['data']['ids'] ?? [];
        $occurredAt = $this->payload['occurred_at'] ?? now();

        Log::info('FIC Webhook: Processing invoices.create event', [
            'account_id' => $this->accountId,
            'ids_count' => count($ids),
            'ids' => $ids,
        ]);

        if (empty($ids)) {
            Log::warning('FIC Webhook: invoices.create event with empty IDs array', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $account = FicAccount::find($this->accountId);
        if (!$account) {
            throw new \RuntimeException("FIC account {$this->accountId} not found");
        }

        $apiService = new FicApiService($account);

        foreach ($ids as $invoiceId) {
            try {
                // Fetch invoice details from FIC API
                $invoiceData = $apiService->fetchIssuedInvoiceById((int) $invoiceId);

                // Upsert invoice into database
                FicInvoice::updateOrCreate(
                    [
                        'fic_account_id' => $this->accountId,
                        'fic_invoice_id' => $invoiceData['id'],
                    ],
                    [
                        'number' => $invoiceData['number'],
                        'status' => $invoiceData['status'],
                        'total_gross' => $invoiceData['total_gross'],
                        'fic_date' => $invoiceData['fic_date'],
                        'fic_created_at' => $invoiceData['fic_created_at'],
                        'raw' => $invoiceData['raw'],
                    ]
                );

                // Create event log entry
                FicEvent::create([
                    'fic_account_id' => $this->accountId,
                    'event_type' => $this->payload['event'] ?? 'it.fattureincloud.webhooks.issued_documents.invoices.create',
                    'resource_type' => 'invoice',
                    'fic_resource_id' => $invoiceData['id'],
                    'occurred_at' => $occurredAt ? new \Carbon\Carbon($occurredAt) : now(),
                    'payload' => $this->payload,
                ]);

                Log::info('FIC Webhook: Invoice synced successfully', [
                    'account_id' => $this->accountId,
                    'invoice_id' => $invoiceData['id'],
                ]);
            } catch (\Exception $e) {
                // Log error but continue processing other IDs
                Log::error('FIC Webhook: Error processing invoice', [
                    'account_id' => $this->accountId,
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Handle entity-related events (backward compatibility).
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

        // For backward compatibility, if action is 'create', try to handle as clients.create
        if ($action === 'create') {
            $this->handleClientsCreate();
        } else {
            Log::debug('FIC Webhook: Unhandled entity action', [
                'action' => $action,
                'account_id' => $this->accountId,
            ]);
        }
    }

    /**
     * Handle issued document events (backward compatibility).
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

        // For backward compatibility, we need to determine document type from payload
        // This is a fallback - prefer using CloudEvents format
        if ($action === 'create') {
            // Try to determine document type from event name or payload
            $eventName = $this->payload['event'] ?? '';
            
            if (str_contains($eventName, 'quote')) {
                $this->handleQuotesCreate();
            } elseif (str_contains($eventName, 'invoice')) {
                $this->handleInvoicesCreate();
            } else {
                Log::debug('FIC Webhook: Cannot determine document type for issued_document.create', [
                    'event' => $eventName,
                    'account_id' => $this->accountId,
                ]);
            }
        } else {
            Log::debug('FIC Webhook: Unhandled issued document action', [
                'action' => $action,
                'account_id' => $this->accountId,
            ]);
        }
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