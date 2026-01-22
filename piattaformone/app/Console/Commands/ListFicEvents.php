<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicEvent;
use Illuminate\Console\Command;

/**
 * Command to list FIC webhook events received and processed.
 *
 * Shows events from the fic_events table with filtering options.
 */
class ListFicEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:list-events
                            {--account-id= : Filter by account ID}
                            {--resource-type= : Filter by resource type (client, quote, invoice)}
                            {--event-type= : Filter by event type}
                            {--limit=50 : Number of events to show}
                            {--json : Output in JSON format}
                            {--since= : Show events since this date (Y-m-d H:i:s or relative like "1 hour ago")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List FIC webhook events received and processed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account-id');
        $resourceType = $this->option('resource-type');
        $eventType = $this->option('event-type');
        $limit = (int) $this->option('limit');
        $jsonOutput = $this->option('json');
        $since = $this->option('since');

        // Build query
        $query = FicEvent::with('ficAccount')
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($accountId) {
            $query->where('fic_account_id', (int) $accountId);
        }

        if ($resourceType) {
            $query->where('resource_type', $resourceType);
        }

        if ($eventType) {
            $query->where('event_type', 'like', "%{$eventType}%");
        }

        if ($since) {
            try {
                $sinceDate = \Carbon\Carbon::parse($since);
                $query->where('occurred_at', '>=', $sinceDate);
            } catch (\Exception $e) {
                $this->error("Invalid date format for --since: {$since}");
                $this->info("Use formats like: '2026-01-21 10:00:00' or '1 hour ago' or '2 days ago'");
                return Command::FAILURE;
            }
        }

        $events = $query->limit($limit)->get();

        if ($events->isEmpty()) {
            if ($jsonOutput) {
                $this->line(json_encode([], JSON_PRETTY_PRINT));
            } else {
                $this->info('No events found.');
            }
            return Command::SUCCESS;
        }

        if ($jsonOutput) {
            $this->outputJson($events);
        } else {
            $this->outputTable($events);
        }

        return Command::SUCCESS;
    }

    /**
     * Output events in table format.
     *
     * @param \Illuminate\Database\Eloquent\Collection $events
     */
    private function outputTable($events): void
    {
        $headers = [
            'ID',
            'Account',
            'Event Type',
            'Resource',
            'Resource ID',
            'Occurred At',
            'Received At',
        ];

        $rows = [];

        foreach ($events as $event) {
            $rows[] = [
                $event->id,
                $event->ficAccount->name ?? "Account #{$event->fic_account_id}",
                $this->formatEventType($event->event_type),
                $event->resource_type,
                $event->fic_resource_id,
                $event->occurred_at?->format('Y-m-d H:i:s') ?? 'N/A',
                $event->created_at->format('Y-m-d H:i:s'),
            ];
        }

        $this->table($headers, $rows);

        // Show summary
        $this->newLine();
        $this->info("Total events: " . $events->count());
        
        // Group by resource type
        $byResource = $events->groupBy('resource_type');
        $this->info("By resource type:");
        foreach ($byResource as $type => $typeEvents) {
            $this->line("  - {$type}: " . $typeEvents->count());
        }

        // Group by event type
        $byEvent = $events->groupBy('event_type');
        $this->info("By event type:");
        foreach ($byEvent as $type => $typeEvents) {
            $this->line("  - " . $this->formatEventType($type) . ": " . $typeEvents->count());
        }
    }

    /**
     * Output events in JSON format.
     *
     * @param \Illuminate\Database\Eloquent\Collection $events
     */
    private function outputJson($events): void
    {
        $data = $events->map(function ($event) {
            return [
                'id' => $event->id,
                'account_id' => $event->fic_account_id,
                'account_name' => $event->ficAccount->name ?? null,
                'event_type' => $event->event_type,
                'resource_type' => $event->resource_type,
                'resource_id' => $event->fic_resource_id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'received_at' => $event->created_at->toIso8601String(),
                'payload' => $event->payload,
            ];
        });

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Format event type for display.
     *
     * @param string $eventType
     * @return string
     */
    private function formatEventType(string $eventType): string
    {
        // Extract meaningful parts
        // e.g., "it.fattureincloud.webhooks.entities.clients.create" -> "clients.create"
        $parts = explode('.', $eventType);
        
        // Find the last meaningful parts (usually resource.action)
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        }
        
        return $eventType;
    }
}
