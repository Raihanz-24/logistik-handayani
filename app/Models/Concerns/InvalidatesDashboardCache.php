<?php

namespace App\Models\Concerns;

use App\Services\DashboardCacheService;

trait InvalidatesDashboardCache
{
    protected static function bootInvalidatesDashboardCache(): void
    {
        $invalidate = static function (): void {
            app(DashboardCacheService::class)->invalidate();
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
