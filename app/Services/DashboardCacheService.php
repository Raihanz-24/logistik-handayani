<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Throwable;

class DashboardCacheService
{
    private const KEY_PREFIX = 'dashboard-data:';

    private const VERSION_KEY = 'dashboard-data:version';

    private ?string $version = null;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttlSeconds = 45,
    ) {}

    public function remember(string $key, Closure $resolver): mixed
    {
        $cacheKey = self::KEY_PREFIX.$this->version().':'.hash('sha256', $key);
        $missing = new \stdClass;

        try {
            $cached = $this->cache->get($cacheKey, $missing);

            if ($cached !== $missing) {
                return $cached;
            }
        } catch (Throwable $exception) {
            report($exception);

            return $resolver();
        }

        $value = $resolver();

        try {
            $this->cache->put($cacheKey, $value, max(1, $this->ttlSeconds));
        } catch (Throwable $exception) {
            // Cache failure must never make the operational dashboard unavailable.
            report($exception);
        }

        return $value;
    }

    public function invalidate(): void
    {
        $this->version = (string) Str::uuid();

        try {
            $this->cache->forever(self::VERSION_KEY, $this->version);
        } catch (Throwable $exception) {
            // Stock and mutation writes must still succeed if the cache is unavailable.
            report($exception);
        }
    }

    private function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        try {
            $version = $this->cache->get(self::VERSION_KEY);

            if (is_string($version) && $version !== '') {
                return $this->version = $version;
            }

            $this->version = (string) Str::uuid();
            $this->cache->forever(self::VERSION_KEY, $this->version);

            return $this->version;
        } catch (Throwable $exception) {
            report($exception);

            return $this->version = 'fallback-'.Str::uuid();
        }
    }
}
