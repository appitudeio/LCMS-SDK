<?php
    namespace LCMS\Cache;

    use LCMS\Core\Database as DB;

    /**
     * Database cache adapter.
     * Stores cache entries in a database table.
     *
     * Required table schema:
     *   CREATE TABLE `cache` (
     *       `key` VARCHAR(255) PRIMARY KEY,
     *       `value` MEDIUMBLOB,
     *       `expires_at` DATETIME,
     *       INDEX `idx_expires` (`expires_at`)
     *   );
     */
    class DatabaseAdapter extends Adapter
    {
        private string $table;

        public function __construct(string $table = 'cache')
        {
            $this->table = $table;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            $row = DB::query(
                "SELECT `value` FROM `{$this->table}` WHERE `key` = ? AND `expires_at` > NOW() LIMIT 1",
                [$key]
            )->asArray()[0] ?? null;

            if (!$row) {
                return $default;
            }

            return unserialize($row['value']);
        }

        public function set(string $key, mixed $value, int $ttl = 3600): void
        {
            $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
            $serialized = serialize($value);

            DB::query(
                "INSERT INTO `{$this->table}` (`key`, `value`, `expires_at`) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires_at` = VALUES(`expires_at`)",
                [$key, $serialized, $expires]
            );
        }

        public function has(string $key): bool
        {
            $row = DB::query(
                "SELECT 1 FROM `{$this->table}` WHERE `key` = ? AND `expires_at` > NOW() LIMIT 1",
                [$key]
            )->asArray()[0] ?? null;

            return $row !== null;
        }

        public function forget(string $key): void
        {
            DB::query("DELETE FROM `{$this->table}` WHERE `key` = ?", [$key]);
        }

        public function flush(): void
        {
            DB::query("TRUNCATE TABLE `{$this->table}`");
        }

        /**
         * Clean up expired entries.
         */
        public function gc(): void
        {
            DB::query("DELETE FROM `{$this->table}` WHERE `expires_at` < NOW()");
        }
    }
?>