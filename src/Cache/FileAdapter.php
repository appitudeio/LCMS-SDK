<?php
namespace LCMS\Cache;

/**
 * File-based cache adapter.
 * Stores cache entries as serialized files.
 */
class FileAdapter extends Adapter
{
    private string $path;

    public function __construct(string $path = '/tmp/lcms_cache')
    {
        $this->path = rtrim($path, '/');

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    private function filename(string $key): string
    {
        return $this->path . '/' . md5($key) . '.cache';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->filename($key);

        if (!file_exists($file)) {
            return $default;
        }

        $data = @unserialize(file_get_contents($file));

        if ($data === false || !isset($data['expires'], $data['value'])) {
            @unlink($file);
            return $default;
        }

        if ($data['expires'] < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $data = serialize([
            'value' => $value,
            'expires' => time() + $ttl
        ]);

        file_put_contents($this->filename($key), $data, LOCK_EX);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): void
    {
        $file = $this->filename($key);

        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public function flush(): void
    {
        $files = glob($this->path . '/*.cache');

        if ($files) {
            array_map('unlink', $files);
        }
    }

    /**
     * Clean up expired entries.
     */
    public function gc(): void
    {
        $files = glob($this->path . '/*.cache');

        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $data = @unserialize(file_get_contents($file));

            if ($data === false || !isset($data['expires']) || $data['expires'] < time()) {
                @unlink($file);
            }
        }
    }
}
