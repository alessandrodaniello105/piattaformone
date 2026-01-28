<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Command to list OAuth-related log entries from Laravel log files.
 *
 * Filters log entries containing "FIC OAuth" and displays them in a readable format.
 */
class ListOAuthLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'oauth:logs
                            {--level= : Filter by log level (debug, info, warning, error)}
                            {--limit=50 : Number of log entries to show}
                            {--json : Output in JSON format}
                            {--since= : Show logs since this date (Y-m-d H:i:s or relative like "1 hour ago")}
                            {--search= : Search for specific text in log messages}
                            {--tail : Show only the most recent entries (like tail -f)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List OAuth-related log entries from Laravel log files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $level = $this->option('level');
        $limit = (int) $this->option('limit');
        $jsonOutput = $this->option('json');
        $since = $this->option('since');
        $search = $this->option('search');
        $tail = $this->option('tail');

        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            $this->error("Log file not found: {$logPath}");

            return Command::FAILURE;
        }

        // Parse log file
        $entries = $this->parseLogFile($logPath);

        // Filter OAuth entries
        $oauthEntries = $this->filterOAuthEntries($entries);

        // Apply filters
        if ($level) {
            $oauthEntries = $oauthEntries->filter(function ($entry) use ($level) {
                return strtolower($entry['level']) === strtolower($level);
            });
        }

        if ($since) {
            try {
                $sinceDate = \Carbon\Carbon::parse($since);
                $oauthEntries = $oauthEntries->filter(function ($entry) use ($sinceDate) {
                    return $entry['date'] >= $sinceDate;
                });
            } catch (\Exception $e) {
                $this->error("Invalid date format for --since: {$since}");
                $this->info("Use formats like: '2026-01-21 10:00:00' or '1 hour ago' or '2 days ago'");

                return Command::FAILURE;
            }
        }

        if ($search) {
            $oauthEntries = $oauthEntries->filter(function ($entry) use ($search) {
                return stripos($entry['message'], $search) !== false ||
                       stripos(json_encode($entry['context'] ?? []), $search) !== false;
            });
        }

        // Apply limit (take most recent)
        if ($tail) {
            $oauthEntries = $oauthEntries->take($limit);
        } else {
            $oauthEntries = $oauthEntries->take($limit);
        }

        if ($oauthEntries->isEmpty()) {
            if ($jsonOutput) {
                $this->line(json_encode([], JSON_PRETTY_PRINT));
            } else {
                $this->info('No OAuth log entries found.');
            }

            return Command::SUCCESS;
        }

        if ($jsonOutput) {
            $this->outputJson($oauthEntries);
        } else {
            $this->outputTable($oauthEntries);
        }

        return Command::SUCCESS;
    }

    /**
     * Parse Laravel log file into structured entries.
     */
    private function parseLogFile(string $logPath): \Illuminate\Support\Collection
    {
        $content = File::get($logPath);
        $entries = collect();

        // Laravel log format: [YYYY-MM-DD HH:MM:SS] local.LEVEL: message {"context": "data"}
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\w+)\.(\w+):\s+(.+?)(?=\[\d{4}-\d{2}-\d{2}|\Z)/s';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $date = $match[1];
            $environment = $match[2];
            $level = $match[3];
            $messageAndContext = trim($match[4]);

            // Try to extract JSON context
            $context = [];
            $message = $messageAndContext;

            // Look for JSON at the end of the message
            if (preg_match('/\s+(\{.*\})$/s', $messageAndContext, $jsonMatch)) {
                try {
                    $context = json_decode($jsonMatch[1], true) ?? [];
                    $message = trim(str_replace($jsonMatch[1], '', $messageAndContext));
                } catch (\Exception $e) {
                    // If JSON parsing fails, keep original message
                }
            }

            $entries->push([
                'date' => \Carbon\Carbon::parse($date),
                'environment' => $environment,
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'raw' => $match[0],
            ]);
        }

        return $entries;
    }

    /**
     * Filter entries that contain "FIC OAuth".
     */
    private function filterOAuthEntries(\Illuminate\Support\Collection $entries): \Illuminate\Support\Collection
    {
        return $entries->filter(function ($entry) {
            return stripos($entry['message'], 'FIC OAuth') !== false ||
                   stripos($entry['raw'], 'FIC OAuth') !== false;
        });
    }

    /**
     * Output entries in table format.
     */
    private function outputTable(\Illuminate\Support\Collection $entries): void
    {
        $headers = [
            'Date',
            'Level',
            'Message',
            'Context',
        ];

        $rows = [];

        foreach ($entries as $entry) {
            $message = $this->truncate($entry['message'], 60);
            $context = ! empty($entry['context']) ? $this->formatContext($entry['context']) : '';

            $rows[] = [
                $entry['date']->format('Y-m-d H:i:s'),
                strtoupper($entry['level']),
                $message,
                $this->truncate($context, 40),
            ];
        }

        $this->table($headers, $rows);

        // Show summary
        $this->newLine();
        $this->info('Total OAuth log entries: '.$entries->count());

        // Group by level
        $byLevel = $entries->groupBy('level');
        $this->info('By level:');
        foreach ($byLevel as $level => $levelEntries) {
            $this->line('  - '.strtoupper($level).': '.$levelEntries->count());
        }
    }

    /**
     * Output entries in JSON format.
     */
    private function outputJson(\Illuminate\Support\Collection $entries): void
    {
        $data = $entries->map(function ($entry) {
            return [
                'date' => $entry['date']->toIso8601String(),
                'level' => $entry['level'],
                'environment' => $entry['environment'],
                'message' => $entry['message'],
                'context' => $entry['context'],
            ];
        });

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Format context array for display.
     */
    private function formatContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $parts[] = "{$key}: {$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Truncate string to specified length.
     */
    private function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3).'...';
    }
}
