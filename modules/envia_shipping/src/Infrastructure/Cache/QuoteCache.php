<?php

declare(strict_types=1);

namespace EnviaShipping\Infrastructure\Cache;

/**
 * File-based cache for shipping quotes to reduce Envia API calls.
 */
class QuoteCache
{
    private string $cacheDir;

    public function __construct(
        private readonly int $ttlSeconds = 600,
        string $cacheDir = ''
    ) {
        $this->cacheDir = $cacheDir ?: (defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ . 'envia_shipping/' : sys_get_temp_dir() . '/envia_shipping_cache/');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
    }

    /**
     * Build a deterministic cache key from shipping quote parameters.
     *
     * @param array<string, mixed> $params
     */
    public static function buildKey(array $params): string
    {
        ksort($params);
        return 'envia_' . md5(json_encode($params) ?: '');
    }

    /**
     * Retrieve a cached value, or null if missing / expired.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $expiresAt = (int)($data['expires_at'] ?? 0);
        if (time() > $expiresAt) {
            return null; // expired
        }

        return is_array($data['payload'] ?? null) ? $data['payload'] : null;
    }

    /**
     * Retrieve a cached value even if expired (stale-on-failure pattern).
     *
     * @return array<string, mixed>|null
     */
    public function getStale(string $key): ?array
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return is_array($data['payload'] ?? null) ? $data['payload'] : null;
    }

    /**
     * Store a value in the cache.
     *
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value): bool
    {
        $file = $this->getFilePath($key);
        $data = json_encode([
            'expires_at' => time() + $this->ttlSeconds,
            'payload' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($data === false) {
            return false;
        }

        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /**
     * Delete a specific cache entry.
     */
    public function delete(string $key): void
    {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Clear all cached entries for this module.
     */
    public function flush(): int
    {
        $count = 0;
        foreach (glob($this->cacheDir . 'envia_*.json') ?: [] as $file) {
            if (@unlink($file)) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Return number of cached entries.
     */
    public function count(): int
    {
        return count(glob($this->cacheDir . 'envia_*.json') ?: []);
    }

    private function getFilePath(string $key): string
    {
        // Sanitize key to prevent directory traversal
        $safeKey = preg_replace('/[^a-z0-9_]/i', '', $key);
        return $this->cacheDir . $safeKey . '.json';
    }
}
