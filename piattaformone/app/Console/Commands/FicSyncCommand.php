<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Models\FicSupplier;
use App\Services\FicApiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to sync FIC resources (clients, suppliers, invoices, quotes) from FIC API.
 *
 * This command fetches resources from FIC API using FicApiService (which uses SDK API classes)
 * and dispatches SyncFicResourceJob for each item to sync them into the local database.
 */
class FicSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:sync
                            {resource? : Resource type to sync (clients, suppliers, invoices, quotes, or all)}
                            {--account-id= : Specific FIC account ID to sync}
                            {--force : Force sync even if resource already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync FIC resources (clients, suppliers, invoices, quotes) from FIC API';

    /**
     * Valid resource types.
     *
     * @var array<string>
     */
    private array $validResources = ['clients', 'suppliers', 'invoices', 'quotes', 'all'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $resource = $this->argument('resource') ?? 'all';

        // Validate resource type
        if (! in_array($resource, $this->validResources, true)) {
            $this->error("Invalid resource type: {$resource}");
            $this->info('Valid resources: '.implode(', ', $this->validResources));

            return Command::FAILURE;
        }

        // Get FIC account
        $account = $this->getFicAccount();
        if (! $account) {
            $this->error('No FIC account found. Please connect an account first.');

            return Command::FAILURE;
        }

        $this->info("Syncing resources for account: {$account->name} (ID: {$account->id})");
        $this->newLine();

        // Initialize API service
        $apiService = new FicApiService($account);

        // Determine which resources to sync
        $resourcesToSync = $resource === 'all'
            ? ['clients', 'suppliers', 'invoices', 'quotes']
            : [$resource];

        $summary = [
            'clients' => ['total' => 0, 'synced' => 0, 'errors' => 0],
            'suppliers' => ['total' => 0, 'synced' => 0, 'errors' => 0],
            'invoices' => ['total' => 0, 'synced' => 0, 'errors' => 0],
            'quotes' => ['total' => 0, 'synced' => 0, 'errors' => 0],
        ];

        // Sync each resource type
        foreach ($resourcesToSync as $resourceType) {
            $this->info("Syncing {$resourceType}...");
            $result = $this->syncResource($apiService, $account, $resourceType);
            $summary[$resourceType] = $result;
            $this->newLine();
        }

        // Display summary
        $this->displaySummary($summary);

        return Command::SUCCESS;
    }

    /**
     * Get FIC account from option or default active account.
     */
    private function getFicAccount(): ?FicAccount
    {
        $accountId = $this->option('account-id');

        if ($accountId) {
            $account = FicAccount::find((int) $accountId);
            if (! $account) {
                $this->error("FIC account with ID {$accountId} not found.");

                return null;
            }

            return $account;
        }

        // Get default active account
        $account = FicAccount::where('status', 'active')
            ->orWhereNull('status')
            ->first();

        if (! $account) {
            $account = FicAccount::first();
        }

        return $account;
    }

    /**
     * Sync a specific resource type with pagination support.
     *
     * @param  FicApiService  $apiService  The API service instance
     * @param  FicAccount  $account  The FIC account
     * @param  string  $resourceType  The resource type (clients, suppliers, invoices, quotes)
     * @return array{total: int, synced: int, errors: int} Summary statistics
     */
    private function syncResource(FicApiService $apiService, FicAccount $account, string $resourceType): array
    {
        $total = 0;
        $synced = 0;
        $errors = 0;
        $page = 1;
        $perPage = 50;

        try {
            // Fetch first page to get total count and process it
            $response = $this->fetchResourceList($apiService, $resourceType, ['page' => $page, 'per_page' => $perPage]);
            $total = $response['total'] ?? 0;
            $lastPage = $response['last_page'] ?? 1;

            if ($total === 0) {
                $this->warn("  No {$resourceType} found.");

                return ['total' => 0, 'synced' => 0, 'errors' => 0];
            }

            $this->info("  Found {$total} {$resourceType} (across {$lastPage} page(s))");

            // Create progress bar
            $progressBar = $this->output->createProgressBar($total);
            $progressBar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
            $progressBar->setMessage('Starting...');
            $progressBar->start();

            // Process first page (already fetched)
            $items = $response['data'] ?? [];
            foreach ($items as $item) {
                try {
                    $result = $this->saveResource($account, $resourceType, $item);
                    if ($result) {
                        $synced++;
                        $ficId = $this->extractFicId($item, $resourceType);
                        $progressBar->setMessage("Synced {$resourceType} #{$ficId}");
                    } else {
                        $errors++;
                        $progressBar->setMessage('Skipping item without ID');
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $progressBar->setMessage('Error syncing item: '.$e->getMessage());
                    Log::error('FIC Sync Command: Error saving resource', [
                        'resource_type' => $resourceType,
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $progressBar->advance();
            }

            // Process remaining pages
            $page = 2;
            while ($page <= $lastPage) {
                $response = $this->fetchResourceList($apiService, $resourceType, ['page' => $page, 'per_page' => $perPage]);
                $items = $response['data'] ?? [];

                foreach ($items as $item) {
                    try {
                        $result = $this->saveResource($account, $resourceType, $item);
                        if ($result) {
                            $synced++;
                            $ficId = $this->extractFicId($item, $resourceType);
                            $progressBar->setMessage("Synced {$resourceType} #{$ficId}");
                        } else {
                            $errors++;
                            $progressBar->setMessage('Skipping item without ID');
                        }
                    } catch (\Exception $e) {
                        $errors++;
                        $progressBar->setMessage('Error syncing item: '.$e->getMessage());
                        Log::error('FIC Sync Command: Error saving resource', [
                            'resource_type' => $resourceType,
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $progressBar->advance();
                }

                $page++;
            }

            $progressBar->setMessage('Complete');
            $progressBar->finish();
            $this->newLine();

            $this->info("  Synced: {$synced}, Errors: {$errors}");

            return [
                'total' => $total,
                'synced' => $synced,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            $this->error("  Failed to sync {$resourceType}: ".$e->getMessage());
            Log::error('FIC Sync Command: Error syncing resource', [
                'resource_type' => $resourceType,
                'account_id' => $account->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'total' => $total,
                'synced' => $synced,
                'errors' => $errors + 1,
            ];
        }
    }

    /**
     * Fetch resource list from FIC API using FicApiService.
     *
     * @param  FicApiService  $apiService  The API service instance
     * @param  string  $resourceType  The resource type
     * @param  array  $filters  Pagination filters
     * @return array Response data with pagination info
     *
     * @throws \Exception If API call fails
     */
    private function fetchResourceList(FicApiService $apiService, string $resourceType, array $filters): array
    {
        return match ($resourceType) {
            'clients' => $apiService->fetchClientsList($filters),
            'suppliers' => $apiService->fetchSuppliersList($filters),
            'invoices' => $apiService->fetchInvoicesList($filters),
            'quotes' => $apiService->fetchQuotesList($filters),
            default => throw new \RuntimeException("Invalid resource type: {$resourceType}"),
        };
    }

    /**
     * Extract FIC ID from resource item.
     *
     * @param  array  $item  Resource item data
     * @param  string  $resourceType  The resource type
     * @return int|null The FIC ID or null if not found
     */
    private function extractFicId(array $item, string $resourceType): ?int
    {
        // All resources should have an 'id' field
        $id = $item['id'] ?? null;

        return $id ? (int) $id : null;
    }

    /**
     * Save resource directly to database.
     *
     * @param  FicAccount  $account  The FIC account
     * @param  string  $resourceType  The resource type (plural)
     * @param  array  $itemData  Resource item data from API
     * @return bool True if saved successfully, false if skipped
     */
    private function saveResource(FicAccount $account, string $resourceType, array $itemData): bool
    {
        return match ($resourceType) {
            'clients' => $this->saveClient($account, $itemData),
            'suppliers' => $this->saveSupplier($account, $itemData),
            'invoices' => $this->saveInvoice($account, $itemData),
            'quotes' => $this->saveQuote($account, $itemData),
            default => false,
        };
    }

    /**
     * Save client to database.
     */
    private function saveClient(FicAccount $account, array $clientData): bool
    {
        if (! isset($clientData['id'])) {
            return false;
        }

        FicClient::updateOrCreate(
            [
                'fic_account_id' => $account->id,
                'fic_client_id' => $clientData['id'],
            ],
            [
                'name' => $clientData['name'] ?? null,
                'code' => $clientData['code'] ?? null,
                'vat_number' => $clientData['vat_number'] ?? null,
                'fic_created_at' => $this->extractFicCreatedAt($clientData),
                'fic_updated_at' => $this->extractFicUpdatedAt($clientData),
                'raw' => $clientData,
            ]
        );

        return true;
    }

    /**
     * Save supplier to database.
     */
    private function saveSupplier(FicAccount $account, array $supplierData): bool
    {
        if (! isset($supplierData['id'])) {
            return false;
        }

        FicSupplier::updateOrCreate(
            [
                'fic_account_id' => $account->id,
                'fic_supplier_id' => $supplierData['id'],
            ],
            [
                'name' => $supplierData['name'] ?? null,
                'code' => $supplierData['code'] ?? null,
                'vat_number' => $supplierData['vat_number'] ?? null,
                'fic_created_at' => $this->extractFicCreatedAt($supplierData),
                'fic_updated_at' => $this->extractFicUpdatedAt($supplierData),
                'raw' => $supplierData,
            ]
        );

        return true;
    }

    /**
     * Save invoice to database.
     */
    private function saveInvoice(FicAccount $account, array $invoiceData): bool
    {
        if (! isset($invoiceData['id'])) {
            return false;
        }

        FicInvoice::updateOrCreate(
            [
                'fic_account_id' => $account->id,
                'fic_invoice_id' => $invoiceData['id'],
            ],
            [
                'number' => $invoiceData['number'] ?? null,
                'status' => $invoiceData['status'] ?? null,
                'total_gross' => $invoiceData['amount_net'] 
                    ?? $invoiceData['total'] 
                    ?? $invoiceData['total_gross'] 
                    ?? null,
                'fic_date' => $this->extractFicDate($invoiceData),
                'fic_created_at' => isset($invoiceData['created_at']) ? Carbon::parse($invoiceData['created_at']) : null,
                'raw' => $invoiceData,
            ]
        );

        return true;
    }

    /**
     * Save quote to database.
     */
    private function saveQuote(FicAccount $account, array $quoteData): bool
    {
        if (! isset($quoteData['id'])) {
            return false;
        }

        FicQuote::updateOrCreate(
            [
                'fic_account_id' => $account->id,
                'fic_quote_id' => $quoteData['id'],
            ],
            [
                'number' => $quoteData['number'] ?? null,
                'status' => $quoteData['status'] ?? null,
                'total_gross' => $quoteData['amount_net'] 
                    ?? $quoteData['total'] 
                    ?? $quoteData['total_gross'] 
                    ?? null,
                'fic_date' => $this->extractFicDate($quoteData),
                'fic_created_at' => isset($quoteData['created_at']) ? Carbon::parse($quoteData['created_at']) : null,
                'raw' => $quoteData,
            ]
        );

        return true;
    }

    /**
     * Display sync summary statistics.
     *
     * @param  array  $summary  Summary statistics per resource type
     */
    private function displaySummary(array $summary): void
    {
        $this->newLine();
        $this->info('=== Sync Summary ===');
        $this->newLine();

        $headers = ['Resource', 'Total', 'Synced', 'Errors'];
        $rows = [];

        foreach ($summary as $resourceType => $stats) {
            $rows[] = [
                ucfirst($resourceType),
                (string) $stats['total'],
                (string) $stats['synced'],
                (string) $stats['errors'],
            ];
        }

        $this->table($headers, $rows);

        // Calculate totals
        $totalItems = array_sum(array_column($summary, 'total'));
        $totalSynced = array_sum(array_column($summary, 'synced'));
        $totalErrors = array_sum(array_column($summary, 'errors'));

        $this->newLine();
        $this->info("Total items: {$totalItems}");
        $this->info("Total synced: {$totalSynced}");
        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        } else {
            $this->info("Total errors: {$totalErrors}");
        }
    }

    /**
     * Extract fic_date from data array, checking both direct 'date' field and 'raw' array.
     *
     * @param  array  $data  The data array (may contain 'date' directly or 'fic_date' in 'raw')
     * @return \Carbon\Carbon|null
     */
    private function extractFicDate(array $data): ?Carbon
    {
        // Try direct 'date' field first (from API response)
        if (isset($data['date']) && !empty($data['date'])) {
            try {
                return Carbon::parse($data['date']);
            } catch (\Exception $e) {
                // Invalid date format, continue to check raw
            }
        }

        // Try from raw array - check for 'fic_date' (as stored in raw JSON)
        if (isset($data['raw']['fic_date']) && !empty($data['raw']['fic_date'])) {
            try {
                return Carbon::parse($data['raw']['fic_date']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Also try 'date' in raw (in case it's stored as 'date' in raw)
        if (isset($data['raw']['date']) && !empty($data['raw']['date'])) {
            try {
                return Carbon::parse($data['raw']['date']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // If data itself is the raw response (when passed directly from API)
        // Check if it's already the raw structure
        if (isset($data['raw']) && is_array($data['raw'])) {
            // Try fic_date first
            if (isset($data['raw']['fic_date']) && !empty($data['raw']['fic_date'])) {
                try {
                    return Carbon::parse($data['raw']['fic_date']);
                } catch (\Exception $e) {
                    // Invalid date format
                }
            }
            // Then try date
            if (isset($data['raw']['date']) && !empty($data['raw']['date'])) {
                try {
                    return Carbon::parse($data['raw']['date']);
                } catch (\Exception $e) {
                    // Invalid date format
                }
            }
        }

        return null;
    }

    /**
     * Extract fic_created_at from data array, checking both direct 'created_at' field and 'raw' array.
     *
     * @param  array  $data  The data array (may contain 'created_at' directly or 'fic_created_at' in 'raw')
     * @return \Carbon\Carbon|null
     */
    private function extractFicCreatedAt(array $data): ?Carbon
    {
        // Try direct 'created_at' field first (from API response)
        if (isset($data['created_at']) && !empty($data['created_at'])) {
            try {
                return Carbon::parse($data['created_at']);
            } catch (\Exception $e) {
                // Invalid date format, continue to check raw
            }
        }

        // Try from raw array - check for 'fic_created_at' (as stored in raw JSON)
        if (isset($data['raw']['fic_created_at']) && !empty($data['raw']['fic_created_at'])) {
            try {
                return Carbon::parse($data['raw']['fic_created_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Also try 'created_at' in raw (in case it's stored as 'created_at' in raw)
        if (isset($data['raw']['created_at']) && !empty($data['raw']['created_at'])) {
            try {
                return Carbon::parse($data['raw']['created_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        return null;
    }

    /**
     * Extract fic_updated_at from data array, checking both direct 'updated_at' field and 'raw' array.
     *
     * @param  array  $data  The data array (may contain 'updated_at' directly or 'fic_updated_at' in 'raw')
     * @return \Carbon\Carbon|null
     */
    private function extractFicUpdatedAt(array $data): ?Carbon
    {
        // Try direct 'updated_at' field first (from API response)
        if (isset($data['updated_at']) && !empty($data['updated_at'])) {
            try {
                return Carbon::parse($data['updated_at']);
            } catch (\Exception $e) {
                // Invalid date format, continue to check raw
            }
        }

        // Try from raw array - check for 'fic_updated_at' (as stored in raw JSON)
        if (isset($data['raw']['fic_updated_at']) && !empty($data['raw']['fic_updated_at'])) {
            try {
                return Carbon::parse($data['raw']['fic_updated_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Also try 'updated_at' in raw (in case it's stored as 'updated_at' in raw)
        if (isset($data['raw']['updated_at']) && !empty($data['raw']['updated_at'])) {
            try {
                return Carbon::parse($data['raw']['updated_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        return null;
    }
}
