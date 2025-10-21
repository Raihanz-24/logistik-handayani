<?php

namespace App\Filament\Widgets;

use App\Models\Produk;
use App\Models\ProdukLokasi;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ProductAlert extends BaseWidget
{
    use HasWidgetShield, InteractsWithPageFilters;
    protected static ?string $heading = 'Stok hampir habis';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;

    public function getTableRecordKey($record): string
    {
        return "{$record->produk_id}-{$record->lokasi_id}";
    }

    public function table(Table $table): Table
    {
        $filters = $this->filters;

        $query = ProdukLokasi::query()
            ->with(['produk', 'lokasi'])
            ->where('stok', '<', 10)
            ->whereHas('mutasiWidget', function (Builder $query) use ($filters) {
                if ($filters['startDate'] ?? null) {
                    $query->whereDate('tanggal', '>=', $filters['startDate']);
                }
                if ($filters['endDate'] ?? null) {
                    $query->whereDate('tanggal', '<=', $filters['endDate']);
                }
            });

        return $table
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->defaultSort('stok')
            ->query($query)
            ->columns([
                TextColumn::make('produk.nama_produk')
                    ->limit(30)
                    ->label('Produk')
                    ->sortable(),

                TextColumn::make('lokasi.nama_lokasi')
                    ->limit(30)
                    ->label('Lokasi')
                    ->sortable(),

                TextColumn::make('stok')
                    ->label('Stok')
                    ->sortable()
                    ->badge()
                    ->color(fn(int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state < 5 => 'warning',
                        default => 'gray',
                    }),
            ]);
    }
}
