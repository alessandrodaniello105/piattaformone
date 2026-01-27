<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FicConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Command to invalidate OAuth-related cache and session data.
 *
 * Clears:
 * - Laravel cache entries for FIC connection status (fic_connection_check_*)
 * - Redis OAuth state entries (fic:oauth:state:*)
 */
class InvalidateOAuthCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'oauth:invalidate-cache
                            {--user= : User ID to invalidate cache for}
                            {--team= : Team ID to invalidate cache for}
                            {--all : Invalidate all OAuth cache entries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Invalidate OAuth session and cache data (Laravel cache and Redis state)';

    /**
     * Cache prefix for FIC connection status
     */
    private const CACHE_PREFIX = 'fic_connection_check_';

    /**
     * Redis key prefix for OAuth state
     */
    private const REDIS_STATE_PREFIX = 'fic:oauth:state:';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->option('user');
        $teamId = $this->option('team');
        $all = $this->option('all');

        if ($all) {
            return $this->invalidateAll();
        }

        if ($userId) {
            return $this->invalidateForUser($userId, $teamId);
        }

        if ($teamId) {
            return $this->invalidateForTeam($teamId);
        }

        // Default: invalidate all
        $this->warn('No specific user or team specified. Use --all to invalidate everything, or specify --user or --team.');
        $this->info('Use --help to see available options.');

        return Command::FAILURE;
    }

    /**
     * Invalidate OAuth cache for a specific user.
     */
    private function invalidateForUser(?string $userId, ?string $teamId): int
    {
        $user = User::find($userId);

        if (! $user) {
            $this->error("User with ID {$userId} not found.");

            return Command::FAILURE;
        }

        $this->info("Invalidating OAuth cache for user: {$user->name} (ID: {$user->id})");

        $clearedCount = 0;

        if ($teamId) {
            // Invalidate for specific user + team combination
            $cacheKey = self::CACHE_PREFIX.$user->id.'_'.$teamId;
            Cache::forget($cacheKey);
            $clearedCount++;
            $this->info("✓ Cleared cache key: {$cacheKey}");
        } else {
            // Invalidate for all teams of this user
            $user->teams->each(function ($team) use ($user, &$clearedCount) {
                $cacheKey = self::CACHE_PREFIX.$user->id.'_'.$team->id;
                Cache::forget($cacheKey);
                $clearedCount++;
                $this->info("✓ Cleared cache key: {$cacheKey} (Team: {$team->name})");
            });
        }

        // Clear Redis OAuth states (these are temporary and should expire anyway, but we'll clear them)
        $redisStatesCleared = $this->clearRedisStates();

        $this->newLine();
        $this->info('✓ Successfully invalidated OAuth cache!');
        $this->info("Cache entries cleared: {$clearedCount}");
        $this->info("Redis state entries cleared: {$redisStatesCleared}");

        return Command::SUCCESS;
    }

    /**
     * Invalidate OAuth cache for a specific team.
     */
    private function invalidateForTeam(string $teamId): int
    {
        $this->info("Invalidating OAuth cache for team ID: {$teamId}");

        $clearedCount = 0;

        try {
            // Get all cache keys matching the pattern for this team
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $redis = Cache::getStore()->connection();
                $pattern = self::CACHE_PREFIX.'*_'.$teamId;
                $keys = $this->scanKeys($redis, $pattern);

                if (! empty($keys)) {
                    $redis->del($keys);
                    $clearedCount = count($keys);
                    foreach ($keys as $key) {
                        $this->info("✓ Cleared cache key: {$key}");
                    }
                } else {
                    $this->info('No cache entries found for this team.');
                }
            } else {
                // Fallback: try to find users in this team and clear their cache
                $team = \App\Models\Team::find($teamId);
                if ($team) {
                    $team->users->each(function ($user) use ($teamId, &$clearedCount) {
                        $cacheKey = self::CACHE_PREFIX.$user->id.'_'.$teamId;
                        Cache::forget($cacheKey);
                        $clearedCount++;
                        $this->info("✓ Cleared cache key: {$cacheKey} (User: {$user->name})");
                    });
                } else {
                    $this->error("Team with ID {$teamId} not found.");

                    return Command::FAILURE;
                }
            }
        } catch (\Exception $e) {
            $this->error("Error clearing cache: {$e->getMessage()}");

            return Command::FAILURE;
        }

        // Clear Redis OAuth states
        $redisStatesCleared = $this->clearRedisStates();

        $this->newLine();
        $this->info('✓ Successfully invalidated OAuth cache!');
        $this->info("Cache entries cleared: {$clearedCount}");
        $this->info("Redis state entries cleared: {$redisStatesCleared}");

        return Command::SUCCESS;
    }

    /**
     * Invalidate all OAuth cache entries.
     */
    private function invalidateAll(): int
    {
        if (! $this->confirm('This will invalidate ALL OAuth cache entries. Are you sure?', true)) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $this->info('Invalidating all OAuth cache entries...');

        $cacheCleared = 0;
        $redisStatesCleared = 0;

        try {
            // Clear Laravel cache entries
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $redis = Cache::getStore()->connection();
                $pattern = self::CACHE_PREFIX.'*';
                $keys = $this->scanKeys($redis, $pattern);

                if (! empty($keys)) {
                    $redis->del($keys);
                    $cacheCleared = count($keys);
                    $this->info("✓ Cleared {$cacheCleared} cache entries");
                } else {
                    $this->info('✓ No cache entries found');
                }
            } else {
                // For non-Redis cache drivers, we can't efficiently clear by pattern
                // So we'll use the service to clear for all users
                $this->warn('Non-Redis cache driver detected. Clearing cache for all users...');
                User::chunk(100, function ($users) use (&$cacheCleared) {
                    foreach ($users as $user) {
                        app(FicConnectionService::class)->clearCache($user);
                        $cacheCleared++;
                    }
                });
                $this->info("✓ Cleared cache for {$cacheCleared} users");
            }

            // Clear Redis OAuth states
            $redisStatesCleared = $this->clearRedisStates();

            $this->newLine();
            $this->info('✓ Successfully invalidated all OAuth cache!');
            $this->info("Cache entries cleared: {$cacheCleared}");
            $this->info("Redis state entries cleared: {$redisStatesCleared}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error clearing cache: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /**
     * Clear OAuth state entries from Redis.
     */
    private function clearRedisStates(): int
    {
        try {
            $pattern = self::REDIS_STATE_PREFIX.'*';
            $keys = $this->scanKeys(Redis::connection(), $pattern);

            if (! empty($keys)) {
                Redis::del($keys);
                $count = count($keys);
                $this->info("✓ Cleared {$count} Redis OAuth state entries");

                return $count;
            }

            return 0;
        } catch (\Exception $e) {
            $this->warn("Could not clear Redis OAuth states: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Scan Redis keys using SCAN instead of KEYS (non-blocking).
     *
     * @param  mixed  $redis  Redis connection instance
     * @param  string  $pattern  Pattern to match (e.g., 'prefix:*')
     * @return array Array of matching keys
     */
    private function scanKeys($redis, string $pattern): array
    {
        $keys = [];
        $cursor = 0;

        do {
            // Use SCAN instead of KEYS for non-blocking operation
            $result = $redis->scan($cursor, [
                'MATCH' => $pattern,
                'COUNT' => 100, // Process 100 keys at a time
            ]);

            $cursor = $result[0];
            $keys = array_merge($keys, $result[1]);
        } while ($cursor !== 0);

        return array_unique($keys);
    }
}
