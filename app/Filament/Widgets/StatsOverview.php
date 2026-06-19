<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BarangResource;
use App\Filament\Resources\LokasiResource;
use App\Filament\Resources\MutasiResource;
use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Models\Mutasi;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class StatsOverview extends Widget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.stats-overview';

    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        [$start, $end] = $this->resolveDateRange();

        $approvedMutations = Mutasi::query()
            ->where('status', 'approved')
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->count();

        return [
            'periodLabel' => $start->translatedFormat('d M').' - '.$end->translatedFormat('d M Y'),
            'cards' => [
                [
                    'label' => 'Total barang',
                    'value' => Barang::query()->count(),
                    'description' => 'Barang terdaftar dalam sistem',
                    'icon' => 'heroicon-o-cube',
                    'tone' => 'amber',
                    'url' => BarangResource::getUrl('index'),
                ],
                [
                    'label' => 'Total stok',
                    'value' => (int) BarangLokasi::query()
                        ->whereHas('lokasi', fn ($query) => $query->gudang())
                        ->sum('stok'),
                    'description' => 'Unit tersedia di seluruh gudang',
                    'icon' => 'heroicon-o-archive-box',
                    'tone' => 'blue',
                    'url' => BarangResource::getUrl('index'),
                ],
                [
                    'label' => 'Mutasi disetujui',
                    'value' => $approvedMutations,
                    'description' => 'Transaksi pada periode aktif',
                    'icon' => 'heroicon-o-arrows-right-left',
                    'tone' => 'green',
                    'url' => MutasiResource::getUrl('index'),
                ],
                [
                    'label' => 'Gudang aktif',
                    'value' => Lokasi::query()->gudang()->count(),
                    'description' => 'Lokasi penyimpanan terdaftar',
                    'icon' => 'heroicon-o-building-storefront',
                    'tone' => 'cyan',
                    'url' => LokasiResource::getUrl('index'),
                ],
            ],
        ];
    }

    private function resolveDateRange(): array
    {
        $end = filled($this->filters['endDate'] ?? null)
            ? Carbon::parse($this->filters['endDate'])->endOfDay()
            : now()->endOfDay();

        $start = filled($this->filters['startDate'] ?? null)
            ? Carbon::parse($this->filters['startDate'])->startOfDay()
            : $end->copy()->subDays(29)->startOfDay();

        return $start->greaterThan($end)
            ? [$end->copy()->startOfDay(), $start->copy()->endOfDay()]
            : [$start, $end];
    }
}
