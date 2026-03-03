<?php
    namespace LCMS\Cache;

    /**
     * APCu cache adapter.
     * Requires the APCu PHP extension.
     */
    class ApcuAdapter extends Adapter
    {
        public function get(string $key, mixed $default = null): mixed
        {
            $value = apcu_fetch($key, $success);
            return $success ? $value : $default;
        }

        public function set(string $key, mixed $value, int $ttl = 3600): void
        {
            apcu_store($key, $value, $ttl);
        }

        public function has(string $key): bool
        {
            return apcu_exists($key);
        }

        public function forget(string $key): void
        {
            apcu_delete($key);
        }

        public function flush(): void
        {
            apcu_clear_cache();
        }
    }
?>