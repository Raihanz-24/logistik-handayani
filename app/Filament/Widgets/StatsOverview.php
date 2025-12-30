<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LokasiResource;
use App\Filament\Resources\ProdukResource;
use App\Filament\Resources\UserResource;
use App\Models\Lokasi;
use App\Models\Produk;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    use HasWidgetShield, InteractsWithPageFilters;

    protected ?string $heading = 'Statistik';
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $filters = $this->filters ?? [];

        $produkQuery = Produk::query();
        $userQuery   = User::query();
        $lokasiQuery = Lokasi::query();

        if (!empty($filters['startDate'])) {
            $produkQuery->whereDate('created_at', '>=', $filters['startDate']);
            $userQuery->whereDate('created_at', '>=', $filters['startDate']);
            $lokasiQuery->whereDate('created_at', '>=', $filters['startDate']);
        }

        if (!empty($filters['endDate'])) {
            $produkQuery->whereDate('created_at', '<=', $filters['endDate']);
            $userQuery->whereDate('created_at', '<=', $filters['endDate']);
            $lokasiQuery->whereDate('created_at', '<=', $filters['endDate']);
        }

        return [
            Stat::make('Total Produk', $produkQuery->count())
                ->url(ProdukResource::getUrl('index'))
                ->description('Klik untuk melihat semua produk'),

            Stat::make('Total Pengguna', $userQuery->count())
                ->url(UserResource::getUrl('index')) // ✅ FIX: tidak hardcode route
                ->description('Klik untuk melihat semua pengguna'),

            Stat::make('Total Lokasi', $lokasiQuery->count())
                ->url(LokasiResource::getUrl('index'))
                ->description('Klik untuk melihat semua lokasi'),
        ];
    }
}
