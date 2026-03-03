<?php
    namespace LCMS\Cache;

    /**
     * Base class for cache adapters.
     * Extend this to create custom adapters.
     */
    abstract class Adapter
    {
        abstract public function get(string $key, mixed $default = null): mixed;
        abstract public function set(string $key, mixed $value, int $ttl = 3600): void;
        abstract public function has(string $key): bool;
        abstract public function forget(string $key): void;
        abstract public function flush(): void;

        /**
         * Get or compute and store a value.
         * Default implementation - override if your backend has native support.
         */
        public function remember(string $key, callable $callback, int $ttl = 3600): mixed
        {
            $cached = $this->get($key);

            if ($cached !== null) {
                return $cached;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        }
    }
?>