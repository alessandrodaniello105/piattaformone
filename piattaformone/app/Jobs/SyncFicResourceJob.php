<?php

namespace App\Jobs;

use App\Events\ResourceSynced;
use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Models\FicSupplier;
use App\Services\FicApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generic job for syncing FIC resources (clients, suppliers, invoices, quotes).
 *
 * This job fetches a resource from FIC API using FicApiService and upserts it
 * into the local database. It dispatches a ResourceSynced event after successful sync.
 */
class SyncFicResourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * Uses exponential backoff: 60s, 120s, 240s for attempts 1, 2, 3.
     *
     * @return array<int> Array of backoff delays in seconds for each retry attempt
     */
    public function backoff(): array
    {
        return [60, 120, 240];
    }

    /**
     * Create a new job instance.
     *
     * @param  string  $resourceType  The resource type (client, supplier, invoice, quote)
     * @param  int  $ficId  The FIC resource ID
     * @param  int  $accountId  The FIC account ID
     * @param  string  $action  The action that triggered the sync (created, updated, deleted)
     */
    public function __construct(
        public string $resourceType,
        public int $ficId,
        public int $accountId,
        public string $action = 'created'
    ) {
        // Set queue connection to redis
        $this->onConnection('redis');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('FIC Sync: Starting resource sync', [
            'resource_type' => $this->resourceType,
            'fic_id' => $this->ficId,
            'account_id' => $this->accountId,
            'action' => $this->action,
        ]);

        try {
            // Load FIC account
            $account = FicAccount::find($this->accountId);
            if (! $account) {
                throw new \RuntimeException("FIC account {$this->accountId} not found");
            }

            // Handle delete action differently
            if ($this->action === 'deleted') {
                $this->handleDelete($account);

                return;
            }

            // Initialize API service
            $apiService = new FicApiService($account);

            // Fetch resource data from FIC API
            $resourceData = $this->fetchResourceData($apiService, $this->resourceType, $this->ficId);

            // Upsert into local database
            $model = $this->upsertResource($account, $this->resourceType, $resourceData);

            // Dispatch ResourceSynced event
            $this->dispatchResourceSyncedEvent($this->resourceType, $this->ficId, $this->accountId, $this->action, $resourceData);

            Log::info('FIC Sync: Resource synced successfully', [
                'resource_type' => $this->resourceType,
                'fic_id' => $this->ficId,
                'account_id' => $this->accountId,
                'action' => $this->action,
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error syncing resource', [
                'resource_type' => $this->resourceType,
                'fic_id' => $this->ficId,
                'account_id' => $this->accountId,
                'action' => $this->action,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle delete action by removing resource from local database.
     *
     * @param  FicAccount  $account  The FIC account
     */
    private function handleDelete(FicAccount $account): void
    {
        $deleted = match ($this->resourceType) {
            'client' => FicClient::where('fic_account_id', $account->id)
                ->where('fic_client_id', $this->ficId)
                ->delete(),
            'supplier' => FicSupplier::where('fic_account_id', $account->id)
                ->where('fic_supplier_id', $this->ficId)
                ->delete(),
            'invoice' => FicInvoice::where('fic_account_id', $account->id)
                ->where('fic_invoice_id', $this->ficId)
                ->delete(),
            'quote' => FicQuote::where('fic_account_id', $account->id)
                ->where('fic_quote_id', $this->ficId)
                ->delete(),
            default => throw new \RuntimeException("Invalid resource type: {$this->resourceType}"),
        };

        if ($deleted > 0) {
            Log::info('FIC Sync: Resource deleted successfully', [
                'resource_type' => $this->resourceType,
                'fic_id' => $this->ficId,
                'account_id' => $this->accountId,
            ]);

            // Dispatch ResourceSynced event with minimal data
            $this->dispatchResourceSyncedEvent(
                $this->resourceType,
                $this->ficId,
                $this->accountId,
                'deleted',
                ['id' => $this->ficId]
            );
        } else {
            Log::warning('FIC Sync: Resource not found for deletion', [
                'resource_type' => $this->resourceType,
                'fic_id' => $this->ficId,
                'account_id' => $this->accountId,
            ]);
        }
    }

    /**
     * Fetch resource data from FIC API based on resource type.
     *
     * @param  FicApiService  $apiService  The API service instance
     * @param  string  $resourceType  The resource type
     * @param  int  $ficId  The FIC resource ID
     * @return array Normalized resource data
     *
     * @throws \RuntimeException If resource type is invalid or API call fails
     */
    private function fetchResourceData(FicApiService $apiService, string $resourceType, int $ficId): array
    {
        return match ($resourceType) {
            'client' => $apiService->fetchClientById($ficId),
            'supplier' => $apiService->fetchSupplierById($ficId),
            'invoice' => $apiService->fetchIssuedInvoiceById($ficId),
            'quote' => $apiService->fetchIssuedQuoteById($ficId),
            default => throw new \RuntimeException("Invalid resource type: {$resourceType}"),
        };
    }

    /**
     * Upsert resource into local database.
     *
     * @param  FicAccount  $account  The FIC account
     * @param  string  $resourceType  The resource type
     * @param  array  $resourceData  Normalized resource data from API
     * @return \Illuminate\Database\Eloquent\Model The upserted model instance
     *
     * @throws \RuntimeException If resource type is invalid
     */
    private function upsertResource(FicAccount $account, string $resourceType, array $resourceData): \Illuminate\Database\Eloquent\Model
    {
        return match ($resourceType) {
            'client' => $this->upsertClient($account, $resourceData),
            'supplier' => $this->upsertSupplier($account, $resourceData),
            'invoice' => $this->upsertInvoice($account, $resourceData),
            'quote' => $this->upsertQuote($account, $resourceData),
            default => throw new \RuntimeException("Invalid resource type: {$resourceType}"),
        };
    }

    /**
     * Upsert client into database.
     *
     * @param  FicAccount  $account  The FIC account
     * @param  array  $clientData  Normalized client data
     * @return FicClient The upserted client model
     */
    private function upsertClient(FicAccount $account, array $clientData): FicClient
    {
        return FicClient::updateOrCreate(
            [
                'fic_account_id' => $account->id,
                'fic_client_id' => $clientData['id'],
            ],
            [
                'name' => $clientData['name'],
                'code' => $clientData['code'],
                'vat_number' => $clientData['vat_number'],
                'fic_created_at' => $clientData['fic_created_at'],
                'fic_updated_at' => $clientData['fic_updated_at'],
                'raw' => $clientData['raw'],
            ]
        );
    }

    /**
     * Upsert supplier into database.
     *
     * @param  FicAccount  $account  The FIC account
     * @param  array  $supplierData  Normalized supplier data
     * @return FicSupplier The upserted supplier model
     */
    private function upsertSupplier(FicAccount $account, array $supplierData): FicSupplier
    {
        return FicSupplier::updateOrCreate(
            [
                'fic_account_id' => $account->id,
                'fic_supplier_id' => $supplierData['id'],
            ],
            [
                'name' => $supplierData['name'],
                'code' => $supplierData['code'],
                'vat_number' => $supplierData['vat_number'],
                'fic_created_at' => $supplierData['fic_created_at'],
                'fic_updated_at' => $supplierData['fic_updated_at'],
                'raw' => $supplierData['raw'],
            ]
        );
    }

    /**
     * Upsert invoice into database.
     *
     * @param  FicAccount  $account  The FIC account
     * @param  array  $invoiceData  Normalized invoice data
     * @return FicInvoice The upserted invoice model
     */
    private function upsertInvoice(FicAccount $account, array $invoiceData): FicInvoice
    {
        return FicInvoice::updateOrCreate(
            [
                'fic_account_id' => $account->id,
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
    }

    /**
     * Upsert quote into database.
     *
     * @param  FicAccount  $account  The FIC account
     * @param  array  $quoteData  Normalized quote data
     * @return FicQuote The upserted quote model
     */
    private function upsertQuote(FicAccount $account, array $quoteData): FicQuote
    {
        return FicQuote::updateOrCreate(
            [
                'fic_account_id' => $account->id,
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
    }

    /**
     * Dispatch ResourceSynced event after successful sync.
     *
     * @param  string  $resourceType  The resource type
     * @param  int  $ficId  The FIC resource ID
     * @param  int  $accountId  The FIC account ID
     * @param  string  $action  The action (created, updated, deleted)
     * @param  array  $data  The synced resource data
     */
    private function dispatchResourceSyncedEvent(
        string $resourceType,
        int $ficId,
        int $accountId,
        string $action,
        array $data
    ): void {
        try {
            $event = new ResourceSynced(
                resourceType: $resourceType,
                ficId: $ficId,
                accountId: $accountId,
                action: $action,
                data: $data
            );

            broadcast($event);

            Log::debug('FIC Sync: ResourceSynced event dispatched', [
                'resource_type' => $resourceType,
                'fic_id' => $ficId,
                'account_id' => $accountId,
                'action' => $action,
            ]);
        } catch (\Exception $e) {
            // Log broadcast error but don't fail the sync
            Log::warning('FIC Sync: Failed to dispatch ResourceSynced event', [
                'resource_type' => $resourceType,
                'fic_id' => $ficId,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FIC Sync: Job failed after all retries', [
            'resource_type' => $this->resourceType,
            'fic_id' => $this->ficId,
            'account_id' => $this->accountId,
            'action' => $this->action,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);
    }
}
