<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ClearRedisQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:clear-redis 
                            {--connection=redis : The queue connection to clear}
                            {--failed : Also clear failed jobs}
                            {--all : Clear all queue-related keys in Redis (more thorough)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all jobs from Redis queue. Useful for removing old/stale jobs.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('connection');
        $clearFailed = $this->option('failed');

        $this->info("Clearing Redis queue: {$connection}");

        try {
            // Get queue name from config
            $queueName = config("queue.connections.{$connection}.queue", 'default');
            $queueKey = "queues:{$queueName}";

            // Check queue length before clearing
            $queueLength = Redis::llen($queueKey);
            $this->info("Found {$queueLength} jobs in queue: {$queueKey}");

            if ($queueLength > 0) {
                // Clear the queue by deleting all items
                Redis::del($queueKey);
                $this->info("✓ Cleared {$queueLength} jobs from queue: {$queueKey}");
            } else {
                $this->info("✓ Queue is already empty: {$queueKey}");
            }

            // Also check for delayed and reserved queues
            $delayedKey = "queues:{$queueName}:delayed";
            $reservedKey = "queues:{$queueName}:reserved";
            $notifyKey = "queues:{$queueName}:notify";

            $keysToCheck = [$delayedKey, $reservedKey, $notifyKey];
            $totalCleared = $queueLength;

            foreach ($keysToCheck as $key) {
                $length = Redis::zcard($key);
                if ($length > 0) {
                    Redis::del($key);
                    $this->info("✓ Cleared {$length} items from: {$key}");
                    $totalCleared += $length;
                }
            }

            // If --all option, also clear any remaining queue keys
            if ($this->option('all')) {
                $allKeysCleared = $this->clearAllQueueKeys();
                $totalCleared += $allKeysCleared;
            }

            // Clear failed jobs if requested
            if ($clearFailed) {
                $failedCount = $this->clearFailedJobs();
                $totalCleared += $failedCount;
            }

            $this->newLine();
            $this->info('✓ Successfully cleared Redis queue!');
            $this->info("Total items cleared: {$totalCleared}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error clearing queue: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /**
     * Clear all queue-related keys from Redis.
     *
     * @return int Number of keys cleared
     */
    private function clearAllQueueKeys(): int
    {
        $this->info('Clearing all queue-related keys from Redis...');

        try {
            $redis = Redis::connection();
            $keys = $redis->keys('queues:*');
            $cleared = 0;

            foreach ($keys as $key) {
                $type = $redis->type($key);
                $length = match ($type) {
                    1 => $redis->llen($key), // List
                    2 => $redis->zcard($key), // Sorted Set
                    3 => $redis->scard($key), // Set
                    default => 0,
                };

                if ($length > 0) {
                    $redis->del($key);
                    $this->info("✓ Cleared {$length} items from: {$key}");
                    $cleared += $length;
                } else {
                    // Delete empty keys too
                    $redis->del($key);
                }
            }

            if ($cleared === 0 && count($keys) === 0) {
                $this->info('✓ No additional queue keys found');
            }

            return $cleared;
        } catch (\Exception $e) {
            $this->warn("Could not clear all queue keys: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Clear failed jobs from database.
     *
     * @return int Number of failed jobs cleared
     */
    private function clearFailedJobs(): int
    {
        $this->info('Clearing failed jobs...');

        try {
            $failedJobs = \DB::table('failed_jobs')->count();
            if ($failedJobs > 0) {
                \DB::table('failed_jobs')->truncate();
                $this->info("✓ Cleared {$failedJobs} failed jobs");
            } else {
                $this->info('✓ No failed jobs to clear');
            }

            return $failedJobs;
        } catch (\Exception $e) {
            $this->warn("Could not clear failed jobs: {$e->getMessage()}");

            return 0;
        }
    }
}
