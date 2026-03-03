<?php
namespace LCMS\Core;

use LCMS\Util\Singleton;

/**
 * Simple cache facade with pluggable adapters.
 *
 * Usage:
 *   Cache::using(new ApcuAdapter());
 *   $data = Cache::remember('key', fn() => expensive(), 3600);
 */
class Cache
{
    use Singleton {
        Singleton::__construct as private SingletonConstructor;
    }

    private $adapter = null;

    /**
     * Set the cache adapter to use.
     */
    protected function using($adapter): void
    {
        $this->adapter = $adapter;
    }

    /**
     * Get the current adapter.
     */
    protected function adapter()
    {
        return $this->adapter;
    }

    /**
     * Get a value from cache.
     */
    protected function get(string $key, mixed $default = null): mixed
    {
        return $this->adapter?->get($key, $default) ?? $default;
    }

    /**
     * Store a value in cache.
     */
    protected function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->adapter?->set($key, $value, $ttl);
    }

    /**
     * Check if a key exists in cache.
     */
    protected function has(string $key): bool
    {
        return $this->adapter?->has($key) ?? false;
    }

    /**
     * Get a value from cache, or compute and store it if not present.
     */
    protected function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        return $this->adapter?->remember($key, $callback, $ttl) ?? $callback();
    }

    /**
     * Remove a value from cache.
     */
    protected function forget(string $key): void
    {
        $this->adapter?->forget($key);
    }

    /**
     * Clear all cached values.
     */
    protected function flush(): void
    {
        $this->adapter?->flush();
    }

    /**
     * Legacy method for HTML page caching.
     * Currently a pass-through; returns input unchanged.
     */
    protected function storeToFile(string $html): string
    {
        return $html;
    }
}
?>