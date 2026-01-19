<?php

namespace App\Console\Commands;

use App\Models\FicSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Command to list all active FIC subscriptions with details.
 *
 * This command displays subscriptions in a table format with various
 * filtering and output options.
 */
class ListFicSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:list-subscriptions
                            {--account-id= : Filter by account ID}
                            {--event-group= : Filter by event group}
                            {--expiring : Show only subscriptions expiring within 15 days}
                            {--json : Output in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all active FIC subscriptions with details';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = FicSubscription::with('ficAccount')
            ->where('is_active', true);

        // Apply filters
        if ($this->option('account-id')) {
            $query->where('fic_account_id', (int) $this->option('account-id'));
        }

        if ($this->option('event-group')) {
            $query->where('event_group', $this->option('event-group'));
        }

        if ($this->option('expiring')) {
            $cutoffDate = now()->addDays(15);
            $query->where('expires_at', '<=', $cutoffDate)
                ->whereNotNull('expires_at');
        }

        $subscriptions = $query->orderBy('fic_account_id')
            ->orderBy('event_group')
            ->get();

        if ($subscriptions->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([], JSON_PRETTY_PRINT));
            } else {
                $this->info('No active subscriptions found.');
            }
            return Command::SUCCESS;
        }

        if ($this->option('json')) {
            $this->outputJson($subscriptions);
        } else {
            $this->outputTable($subscriptions);
        }

        return Command::SUCCESS;
    }

    /**
     * Output subscriptions in table format.
     *
     * @param Collection<FicSubscription> $subscriptions
     */
    private function outputTable(Collection $subscriptions): void
    {
        $headers = [
            'Account ID',
            'Account Name',
            'Subscription ID',
            'Event Group',
            'Expires At',
            'Status',
        ];

        $rows = [];
        $now = now();

        foreach ($subscriptions as $subscription) {
            $account = $subscription->ficAccount;
            $accountName = $account ? ($account->name ?? $account->company_name ?? 'N/A') : 'N/A';

            $expiresAt = $subscription->expires_at
                ? $subscription->expires_at->format('Y-m-d H:i:s')
                : 'N/A';

            // Determine status
            if (!$subscription->expires_at) {
                $status = 'Active';
                $statusColor = 'green';
            } elseif ($subscription->expires_at->isPast()) {
                $status = 'Expired';
                $statusColor = 'red';
            } elseif ($subscription->expires_at->diffInDays($now, false) <= 15) {
                $daysUntil = max(0, (int) $subscription->expires_at->diffInDays($now, false));
                $status = 'Expiring (' . $daysUntil . ' days)';
                $statusColor = 'yellow';
            } else {
                $status = 'Active';
                $statusColor = 'green';
            }

            $rows[] = [
                (string) $subscription->fic_account_id,
                $accountName,
                $subscription->fic_subscription_id,
                $subscription->event_group,
                $expiresAt,
                $status,
            ];
        }

        $this->table($headers, $rows);

        // Show summary
        $this->newLine();
        $expiringCount = $subscriptions->filter(function ($sub) use ($now) {
            return $sub->expires_at
                && $sub->expires_at->isFuture()
                && $sub->expires_at->diffInDays($now) <= 15;
        })->count();

        $expiredCount = $subscriptions->filter(function ($sub) use ($now) {
            return $sub->expires_at && $sub->expires_at->isPast();
        })->count();

        $this->info("Total: {$subscriptions->count()} subscription(s)");
        if ($expiringCount > 0) {
            $this->warn("Expiring within 15 days: {$expiringCount}");
        }
        if ($expiredCount > 0) {
            $this->error("Expired: {$expiredCount}");
        }
    }

    /**
     * Output subscriptions in JSON format.
     *
     * @param Collection<FicSubscription> $subscriptions
     */
    private function outputJson(Collection $subscriptions): void
    {
        $data = $subscriptions->map(function ($subscription) {
            $account = $subscription->ficAccount;
            $now = now();

            // Determine status
            if (!$subscription->expires_at) {
                $status = 'active';
            } elseif ($subscription->expires_at->isPast()) {
                $status = 'expired';
            } elseif ($subscription->expires_at->diffInDays($now) <= 15) {
                $status = 'expiring';
            } else {
                $status = 'active';
            }

            return [
                'id' => $subscription->id,
                'account_id' => $subscription->fic_account_id,
                'account_name' => $account ? ($account->name ?? $account->company_name ?? null) : null,
                'subscription_id' => $subscription->fic_subscription_id,
                'event_group' => $subscription->event_group,
                'expires_at' => $subscription->expires_at?->toIso8601String(),
                'expires_in_days' => $subscription->expires_at?->diffInDays($now),
                'status' => $status,
            ];
        })->values()->all();

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}