<?php

namespace App\Filament\Widgets;

use App\Models\BarangLokasi;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

class BarangAlert extends Widget
{
    use HasWidgetShield;

    protected static string $view = 'filament.widgets.barang-alert';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $rows = BarangLokasi::query()
            ->with(['barang:id,nama_barang,kode_barang,satuan', 'lokasi:id,nama_lokasi'])
            ->whereHas('lokasi', fn ($query) => $query->gudang())
            ->where('stok', '<', 10)
            ->orderBy('stok')
            ->limit(8)
            ->get(['barang_id', 'lokasi_id', 'stok'])
            ->map(fn (BarangLokasi $stock): array => [
                'name' => $stock->barang?->nama_barang ?? 'Barang',
                'code' => $stock->barang?->kode_barang ?? '-',
                'location' => $stock->lokasi?->nama_lokasi ?? 'Lokasi',
                'stock' => (int) $stock->stok,
                'unit' => $stock->barang?->satuan ?? 'unit',
                'tone' => match (true) {
                    $stock->stok <= 0 => 'danger',
                    $stock->stok <= 3 => 'warning',
                    default => 'amber',
                },
            ]);

        return [
            'rows' => $rows,
            'maxStock' => max(10, (int) $rows->max('stock')),
            'criticalCount' => $rows->where('stock', '<=', 3)->count(),
        ];
    }
}
