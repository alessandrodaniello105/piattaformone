<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Services\FicApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to diagnose FIC webhook subscriptions and verify they are working.
 *
 * This command fetches subscriptions from the FIC API and checks:
 * - If subscriptions are verified
 * - If webhook URLs are correct
 * - If subscriptions are active
 * - If event types are configured correctly
 */
class DiagnoseFicWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:diagnose-webhooks
                            {--account-id= : Filter by account ID}
                            {--check-urls : Verify webhook URLs are accessible}
                            {--json : Output in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose FIC webhook subscriptions by fetching them from the API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account-id');
        $checkUrls = $this->option('check-urls');
        $jsonOutput = $this->option('json');

        // Get accounts to check
        $accounts = $accountId 
            ? FicAccount::where('id', $accountId)->get()
            : FicAccount::all();

        if ($accounts->isEmpty()) {
            $this->error('No FIC accounts found.');
            return Command::FAILURE;
        }

        $results = [];

        foreach ($accounts as $account) {
            $this->info("Checking account: {$account->name} (ID: {$account->id}, Company ID: {$account->company_id})");

            try {
                $apiService = new FicApiService($account);
                $subscriptions = $apiService->fetchSubscriptions();

                if (empty($subscriptions)) {
                    $this->warn("  No subscriptions found for this account.");
                    $results[] = [
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                        'company_id' => $account->company_id,
                        'subscriptions' => [],
                        'status' => 'no_subscriptions',
                    ];
                    continue;
                }

                $this->info("  Found " . count($subscriptions) . " subscription(s):");

                $accountResults = [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'company_id' => $account->company_id,
                    'subscriptions' => [],
                ];

                foreach ($subscriptions as $subscription) {
                    $subId = $subscription['id'] ?? 'unknown';
                    $sink = $subscription['sink'] ?? 'N/A';
                    $verified = $subscription['verified'] ?? false;
                    $types = $subscription['types'] ?? [];
                    $config = $subscription['config'] ?? [];
                    $mapping = $config['mapping'] ?? 'unknown';

                    $status = $verified ? '✓ Verified' : '✗ Not Verified';
                    $statusColor = $verified ? 'green' : 'red';

                    $this->line("  Subscription: <fg=cyan>{$subId}</>");
                    $this->line("    Status: <fg={$statusColor}>{$status}</>");
                    $this->line("    URL: <fg=yellow>{$sink}</>");
                    $this->line("    Mapping: <fg=magenta>{$mapping}</>");
                    $this->line("    Event Types: " . count($types) . " type(s)");

                    if (!empty($types)) {
                        foreach ($types as $type) {
                            $this->line("      - {$type}");
                        }
                    }

                    // Check if URL is accessible
                    $urlAccessible = null;
                    $urlError = null;
                    if ($checkUrls && $sink !== 'N/A') {
                        try {
                            $ch = curl_init($sink);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_NOBODY, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            if ($httpCode >= 200 && $httpCode < 400) {
                                $urlAccessible = true;
                                $this->line("    URL Check: <fg=green>✓ Accessible (HTTP {$httpCode})</>");
                            } else {
                                $urlAccessible = false;
                                $urlError = "HTTP {$httpCode}";
                                $this->line("    URL Check: <fg=red>✗ Not accessible (HTTP {$httpCode})</>");
                            }
                        } catch (\Exception $e) {
                            $urlAccessible = false;
                            $urlError = $e->getMessage();
                            $this->line("    URL Check: <fg=red>✗ Error: {$urlError}</>");
                        }
                    }

                    // Verify URL format matches our expected pattern
                    $expectedPattern = '/\/api\/webhooks\/fic\/(\d+)\/([a-z_]+)/';
                    $urlMatches = preg_match($expectedPattern, $sink, $matches);
                    $extractedAccountId = null;
                    $extractedGroup = null;
                    
                    if ($urlMatches) {
                        $extractedAccountId = $matches[1] ?? null;
                        $extractedGroup = $matches[2] ?? null;
                        
                        if ($extractedAccountId && $extractedAccountId != $account->id) {
                            $this->warn("    ⚠ Warning: URL account ID ({$extractedAccountId}) doesn't match account ID ({$account->id})");
                        }
                    } else {
                        $this->warn("    ⚠ Warning: URL doesn't match expected pattern: /api/webhooks/fic/{account_id}/{group}");
                    }

                    $accountResults['subscriptions'][] = [
                        'id' => $subId,
                        'sink' => $sink,
                        'verified' => $verified,
                        'types' => $types,
                        'mapping' => $mapping,
                        'url_accessible' => $urlAccessible,
                        'url_error' => $urlError,
                        'url_matches_pattern' => $urlMatches,
                        'extracted_account_id' => $extractedAccountId ?? null,
                        'extracted_group' => $extractedGroup ?? null,
                    ];

                    $this->line('');
                }

                $results[] = $accountResults;

            } catch (\Exception $e) {
                $this->error("  Error fetching subscriptions: " . $e->getMessage());
                Log::error('FIC Webhook Diagnosis: Error', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $results[] = [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'company_id' => $account->company_id,
                    'error' => $e->getMessage(),
                    'status' => 'error',
                ];
            }

            $this->line('');
        }

        // Summary
        $this->info('=== Summary ===');
        $totalSubscriptions = 0;
        $verifiedSubscriptions = 0;
        $unverifiedSubscriptions = 0;

        foreach ($results as $result) {
            if (isset($result['subscriptions'])) {
                foreach ($result['subscriptions'] as $sub) {
                    $totalSubscriptions++;
                    if ($sub['verified']) {
                        $verifiedSubscriptions++;
                    } else {
                        $unverifiedSubscriptions++;
                    }
                }
            }
        }

        $this->line("Total subscriptions: {$totalSubscriptions}");
        $this->line("Verified: <fg=green>{$verifiedSubscriptions}</>");
        $this->line("Not verified: <fg=red>{$unverifiedSubscriptions}</>");

        if ($jsonOutput) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }
}
