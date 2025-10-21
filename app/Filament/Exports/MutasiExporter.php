<?php

namespace App\Filament\Exports;

use App\Models\Mutasi;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class MutasiExporter extends Exporter
{
    protected static ?string $model = Mutasi::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('tanggal'),
            ExportColumn::make('jenis_mutasi'),
            ExportColumn::make('jumlah'),
            ExportColumn::make('keterangan'),
            ExportColumn::make('no_ref'),
            ExportColumn::make('status'),
            ExportColumn::make('user.name'),
            ExportColumn::make('produk.id'),
            ExportColumn::make('lokasi.id'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
            ExportColumn::make('created_by'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your mutasi export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
