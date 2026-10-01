<?php

namespace App\Filament\Widgets;

use App\Models\Lokasi;
use App\Models\User;
use App\Services\DashboardCacheService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class BarangAlert extends Widget
{
    use HasWidgetShield;

    protected static string $view = 'filament.widgets.barang-alert';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->hasRole('super_admin') || $user->can('view_dashboard_monitoring'));
    }

    protected function getViewData(): array
    {
        $rows = collect(app(DashboardCacheService::class)->remember(
            'low-stock:v2:all-warehouses',
            fn (): array => DB::table('barang_lokasi')
                ->join('lokasis', 'lokasis.id', '=', 'barang_lokasi.lokasi_id')
                ->join('barangs', 'barangs.id', '=', 'barang_lokasi.barang_id')
                ->where('lokasis.jenis_lokasi', Lokasi::JENIS_GUDANG)
                ->select([
                    'barangs.nama_barang as name',
                    'barangs.kode_barang as code',
                    'barangs.satuan as unit',
                ])
                ->selectRaw('COALESCE(SUM(barang_lokasi.stok), 0) as stock')
                ->groupBy('barangs.id', 'barangs.nama_barang', 'barangs.kode_barang', 'barangs.satuan')
                ->havingRaw('COALESCE(SUM(barang_lokasi.stok), 0) < ?', [10])
                ->orderBy('stock')
                ->limit(8)
                ->get()
                ->map(fn (object $stock): array => [
                    'name' => $stock->name ?? 'Barang',
                    'code' => $stock->code ?? '-',
                    'location' => 'Akumulasi semua gudang',
                    'stock' => (int) $stock->stock,
                    'unit' => $stock->unit ?? 'unit',
                    'tone' => match (true) {
                        $stock->stock <= 0 => 'danger',
                        $stock->stock <= 3 => 'warning',
                        default => 'amber',
                    },
                ])
                ->all(),
        ));

        return [
            'rows' => $rows,
            'maxStock' => max(10, (int) $rows->max('stock')),
            'criticalCount' => $rows->where('stock', '<=', 3)->count(),
        ];
    }
}
