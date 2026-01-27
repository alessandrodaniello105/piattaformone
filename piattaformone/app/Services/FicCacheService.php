<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class FicCacheService
{
    /**
     * Cache TTL in seconds (10 minutes)
     */
    private const TTL = 600;

    /**
     * Cache key prefixes for different resource types
     */
    private const KEYS = [
        'clients' => 'fic:clients',
        'suppliers' => 'fic:suppliers',
        'quotes' => 'fic:quotes',
        'invoices' => 'fic:invoices',
    ];

    /**
     * Get cached data for a specific resource type and page.
     */
    public function get(string $type, int $page, int $perPage, ?int $teamId = null): ?array
    {
        $key = $this->getCacheKey($type, $page, $perPage, $teamId);

        return Cache::get($key);
    }

    /**
     * Store data in cache for a specific resource type and page.
     */
    public function put(string $type, int $page, int $perPage, array $data, array $meta, ?int $teamId = null): void
    {
        $key = $this->getCacheKey($type, $page, $perPage, $teamId);

        Cache::put($key, [
            'data' => $data,
            'meta' => $meta,
        ], self::TTL);
    }

    /**
     * Invalidate all cached pages for a specific resource type and team.
     */
    public function invalidate(string $type, ?int $teamId = null): void
    {
        if (! isset(self::KEYS[$type])) {
            return;
        }

        $prefix = self::KEYS[$type];
        if ($teamId !== null) {
            $prefix .= ':team:'.$teamId;
        }

        // Clear all cache keys with this prefix
        // Use SCAN instead of KEYS for non-blocking operation
        $pattern = $prefix.':*';

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $this->scanKeys($redis, $pattern);

            if (! empty($keys)) {
                $redis->del($keys);
            }
        } else {
            // Fallback for non-Redis cache drivers
            // This won't work efficiently without Redis, but provides compatibility
            Cache::forget($prefix);
        }
    }

    /**
     * Invalidate all FIC cache for a specific team.
     */
    public function invalidateAll(?int $teamId = null): void
    {
        foreach (array_keys(self::KEYS) as $type) {
            $this->invalidate($type, $teamId);
        }
    }

    /**
     * Generate cache key for a specific resource type, page, per_page, and team.
     */
    private function getCacheKey(string $type, int $page, int $perPage, ?int $teamId = null): string
    {
        if (! isset(self::KEYS[$type])) {
            throw new \InvalidArgumentException("Invalid resource type: {$type}");
        }

        $key = self::KEYS[$type];

        if ($teamId !== null) {
            $key .= ':team:'.$teamId;
        }

        $key .= ':page:'.$page.':perpage:'.$perPage;

        return $key;
    }

    /**
     * Get all supported resource types.
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::KEYS);
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
