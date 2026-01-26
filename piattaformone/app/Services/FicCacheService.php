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
    public function get(string $type, int $page, int $perPage): ?array
    {
        $key = $this->getCacheKey($type, $page, $perPage);

        return Cache::get($key);
    }

    /**
     * Store data in cache for a specific resource type and page.
     */
    public function put(string $type, int $page, int $perPage, array $data, array $meta): void
    {
        $key = $this->getCacheKey($type, $page, $perPage);

        Cache::put($key, [
            'data' => $data,
            'meta' => $meta,
        ], self::TTL);
    }

    /**
     * Invalidate all cached pages for a specific resource type.
     */
    public function invalidate(string $type): void
    {
        if (! isset(self::KEYS[$type])) {
            return;
        }

        $prefix = self::KEYS[$type];

        // Clear all cache keys with this prefix
        // Redis supports pattern matching with KEYS command
        // For production, consider using SCAN instead for better performance
        $pattern = $prefix.':*';

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($pattern);

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
     * Invalidate all FIC cache.
     */
    public function invalidateAll(): void
    {
        foreach (array_keys(self::KEYS) as $type) {
            $this->invalidate($type);
        }
    }

    /**
     * Generate cache key for a specific resource type, page, and per_page.
     */
    private function getCacheKey(string $type, int $page, int $perPage): string
    {
        if (! isset(self::KEYS[$type])) {
            throw new \InvalidArgumentException("Invalid resource type: {$type}");
        }

        return self::KEYS[$type].':page:'.$page.':perpage:'.$perPage;
    }

    /**
     * Get all supported resource types.
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::KEYS);
    }
}
