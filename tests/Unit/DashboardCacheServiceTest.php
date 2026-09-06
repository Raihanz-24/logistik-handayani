<?php

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\Concerns\InvalidatesDashboardCache;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Services\DashboardCacheService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class DashboardCacheServiceTest extends TestCase
{
    public function test_it_reuses_cached_data_and_rotates_the_namespace_when_invalidated(): void
    {
        $service = new DashboardCacheService(new Repository(new ArrayStore), 45);
        $resolutions = 0;
        $resolver = function () use (&$resolutions): array {
            $resolutions++;

            return ['resolution' => $resolutions];
        };

        $this->assertSame(['resolution' => 1], $service->remember('stats', $resolver));
        $this->assertSame(['resolution' => 1], $service->remember('stats', $resolver));
        $this->assertSame(1, $resolutions);

        $service->invalidate();

        $this->assertSame(['resolution' => 2], $service->remember('stats', $resolver));
        $this->assertSame(2, $resolutions);
    }

    public function test_dashboard_models_invalidate_the_shared_cache(): void
    {
        $this->assertSame(
            app(DashboardCacheService::class),
            app(DashboardCacheService::class),
        );

        foreach ([Barang::class, BarangLokasi::class, Lokasi::class, Mutasi::class] as $model) {
            $this->assertContains(InvalidatesDashboardCache::class, class_uses_recursive($model));
        }
    }
}
