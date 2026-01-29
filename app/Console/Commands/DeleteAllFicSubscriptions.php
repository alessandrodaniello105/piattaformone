<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to delete all FIC subscriptions from both API and local database.
 *
 * This command is useful for cleanup when subscriptions need to be recreated
 * (e.g., after changing subscription strategy or fixing issues).
 */
class DeleteAllFicSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:delete-all-subscriptions
                            {--account-id= : Only delete subscriptions for specific account ID}
                            {--local-only : Only delete from local DB, not from FIC API}
                            {--api-only : Only delete from FIC API, not from local DB}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all FIC subscriptions from API and local database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account-id');
        $localOnly = $this->option('local-only');
        $apiOnly = $this->option('api-only');
        $force = $this->option('force');

        // Get accounts to process
        $accounts = $accountId
            ? FicAccount::where('id', $accountId)->get()
            : FicAccount::all();

        if ($accounts->isEmpty()) {
            $this->error('No FIC accounts found.');

            return Command::FAILURE;
        }

        // Confirmation
        if (! $force) {
            $accountNames = $accounts->pluck('company_name')->join(', ');
            $this->warn('This will delete ALL subscriptions for: '.$accountNames);

            if (! $localOnly) {
                $this->warn('Subscriptions will be deleted from FIC API (cannot be undone).');
            }
            if (! $apiOnly) {
                $this->warn('Subscriptions will be deleted from local database.');
            }

            if (! $this->confirm('Are you sure you want to continue?')) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        $totalDeleted = 0;
        $totalErrors = 0;

        foreach ($accounts as $account) {
            $this->info("Processing account: {$account->company_name} (ID: {$account->id})");

            try {
                // Delete from FIC API
                if (! $localOnly) {
                    $apiService = new FicApiService($account);
                    $subscriptions = $apiService->fetchSubscriptions();

                    $this->info('  Found '.count($subscriptions).' subscription(s) in FIC API');

                    foreach ($subscriptions as $subscription) {
                        $ficSubId = $subscription['id'] ?? null;
                        if (! $ficSubId) {
                            continue;
                        }

                        try {
                            $deleted = $apiService->deleteSubscription($ficSubId);

                            if ($deleted) {
                                $this->line("  ✓ Deleted subscription from FIC API: {$ficSubId}");
                                $totalDeleted++;
                            } else {
                                $this->warn("  ✗ Failed to delete subscription from FIC API: {$ficSubId}");
                                $totalErrors++;
                            }
                        } catch (\Exception $e) {
                            $this->error("  ✗ Error deleting subscription {$ficSubId}: ".$e->getMessage());
                            $totalErrors++;
                            Log::error('Delete FIC Subscription: Error', [
                                'account_id' => $account->id,
                                'fic_subscription_id' => $ficSubId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                // Delete from local database
                if (! $apiOnly) {
                    $localSubscriptions = FicSubscription::where('fic_account_id', $account->id)->get();
                    $deletedCount = FicSubscription::where('fic_account_id', $account->id)->delete();

                    if ($deletedCount > 0) {
                        $this->line("  ✓ Deleted {$deletedCount} subscription(s) from local database");
                    }
                }
            } catch (\Exception $e) {
                $this->error('  Error processing account: '.$e->getMessage());
                $totalErrors++;
                Log::error('Delete All FIC Subscriptions: Error', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $this->newLine();
        }

        // Summary
        $this->info('=== Summary ===');
        $this->line("Total subscriptions deleted: {$totalDeleted}");
        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        }

        return Command::SUCCESS;
    }
}
