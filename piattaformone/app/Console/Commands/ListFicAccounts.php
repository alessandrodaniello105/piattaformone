<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use Illuminate\Console\Command;

/**
 * Command to list all FIC accounts with their details.
 *
 * This command helps identify which account_id to use
 * when creating subscriptions or performing other operations.
 */
class ListFicAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:list-accounts
                            {--json : Output in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all FIC accounts with their details';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accounts = FicAccount::orderBy('id')->get();

        if ($accounts->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([], JSON_PRETTY_PRINT));
            } else {
                $this->warn('No FIC accounts found.');
                $this->newLine();
                $this->info('To create an account, you need to:');
                $this->line('1. Connect via OAuth: Visit /api/fic/oauth/redirect');
                $this->line('2. Or create manually using Tinker or a seeder');
            }
            return Command::SUCCESS;
        }

        if ($this->option('json')) {
            $this->outputJson($accounts);
        } else {
            $this->outputTable($accounts);
        }

        return Command::SUCCESS;
    }

    /**
     * Output accounts in table format.
     *
     * @param \Illuminate\Database\Eloquent\Collection<FicAccount> $accounts
     */
    private function outputTable($accounts): void
    {
        $headers = [
            'ID',
            'Name',
            'Company ID (FIC)',
            'Company Name',
            'Status',
            'Has Token',
            'Connected At',
            'Subscriptions',
        ];

        $rows = [];

        foreach ($accounts as $account) {
            $hasToken = !empty($account->access_token) ? '✓ Yes' : '✗ No';
            $connectedAt = $account->connected_at
                ? $account->connected_at->format('Y-m-d H:i:s')
                : 'N/A';
            $subscriptionsCount = $account->subscriptions()->where('is_active', true)->count();

            $rows[] = [
                $account->id,
                $account->name ?? 'N/A',
                $account->company_id ?? 'N/A',
                $account->company_name ?? 'N/A',
                $account->status ?? 'active',
                $hasToken,
                $connectedAt,
                $subscriptionsCount,
            ];
        }

        $this->table($headers, $rows);

        // Show summary
        $this->newLine();
        $this->info("Total: {$accounts->count()} account(s)");
        
        $withToken = $accounts->filter(fn($acc) => !empty($acc->access_token))->count();
        $withoutToken = $accounts->count() - $withToken;
        
        if ($withToken > 0) {
            $this->info("With access token: {$withToken}");
        }
        if ($withoutToken > 0) {
            $this->warn("Without access token: {$withoutToken}");
        }
    }

    /**
     * Output accounts in JSON format.
     *
     * @param \Illuminate\Database\Eloquent\Collection<FicAccount> $accounts
     */
    private function outputJson($accounts): void
    {
        $data = $accounts->map(function ($account) {
            return [
                'id' => $account->id,
                'name' => $account->name,
                'company_id' => $account->company_id,
                'company_name' => $account->company_name,
                'company_email' => $account->company_email,
                'status' => $account->status,
                'has_access_token' => !empty($account->access_token),
                'connected_at' => $account->connected_at?->toIso8601String(),
                'subscriptions_count' => $account->subscriptions()->where('is_active', true)->count(),
            ];
        })->values()->all();

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
