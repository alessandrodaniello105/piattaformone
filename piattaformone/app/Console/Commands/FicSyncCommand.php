<?php

namespace App\Console\Commands;

use App\Jobs\SyncFicResourceJob;
use App\Models\FicAccount;
use App\Services\FicApiService;
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
                    $ficId = $this->extractFicId($item, $resourceType);
                    if (! $ficId) {
                        $progressBar->setMessage('Skipping item without ID');
                        $errors++;

                        continue;
                    }

                    // Dispatch sync job
                    SyncFicResourceJob::dispatch(
                        $this->normalizeResourceType($resourceType),
                        (int) $ficId,
                        $account->id,
                        'created'
                    )->onConnection('redis');

                    $synced++;
                    $progressBar->setMessage("Synced {$resourceType} #{$ficId}");
                } catch (\Exception $e) {
                    $errors++;
                    $progressBar->setMessage('Error syncing item: '.$e->getMessage());
                    Log::error('FIC Sync Command: Error dispatching sync job', [
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
                        $ficId = $this->extractFicId($item, $resourceType);
                        if (! $ficId) {
                            $progressBar->setMessage('Skipping item without ID');
                            $errors++;

                            continue;
                        }

                        // Dispatch sync job
                        SyncFicResourceJob::dispatch(
                            $this->normalizeResourceType($resourceType),
                            (int) $ficId,
                            $account->id,
                            'created'
                        )->onConnection('redis');

                        $synced++;
                        $progressBar->setMessage("Synced {$resourceType} #{$ficId}");
                    } catch (\Exception $e) {
                        $errors++;
                        $progressBar->setMessage('Error syncing item: '.$e->getMessage());
                        Log::error('FIC Sync Command: Error dispatching sync job', [
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
     * Normalize resource type for SyncFicResourceJob (plural to singular).
     *
     * @param  string  $resourceType  The resource type (plural)
     * @return string Normalized resource type (singular)
     */
    private function normalizeResourceType(string $resourceType): string
    {
        return match ($resourceType) {
            'clients' => 'client',
            'suppliers' => 'supplier',
            'invoices' => 'invoice',
            'quotes' => 'quote',
            default => $resourceType,
        };
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
}
