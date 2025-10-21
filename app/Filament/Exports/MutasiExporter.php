<?php

namespace App\Filament\Exports;

use App\Models\Mutasi;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;

class MutasiExporter extends Exporter
{
    protected static ?string $model = Mutasi::class;

    public static function modifyQuery(Builder $query): Builder
    {
        $t = $query->getModel()->getTable(); // ex: 'mutasis'

        return $query
            ->select([
                "{$t}.id",
                "{$t}.tanggal",
                "{$t}.jenis_mutasi",
                "{$t}.jumlah",
                "{$t}.keterangan",
                "{$t}.no_ref",
                "{$t}.status",
                "{$t}.user_id",
                "{$t}.produk_id",
                "{$t}.lokasi_id",
                "{$t}.created_at",
                "{$t}.updated_at",
                "{$t}.created_by",
            ])
            ->with([
                'user:id,name',
                'produk:id,nama_produk',
                'lokasi:id,nama_lokasi',
            ])
            ->orderBy("{$t}.id", 'desc');
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),

            ExportColumn::make('tanggal')
                ->label('Tanggal')
                ->formatStateUsing(function ($state, $record) {
                    $v = $record->tanggal;
                    if ($v instanceof CarbonInterface) {
                        return $v->format('Y-m-d H:i:s');
                    }
                    return is_string($v) ? $v : null;
                }),

            ExportColumn::make('jenis_mutasi')->label('Jenis Mutasi'),

            ExportColumn::make('jumlah')->label('Jumlah'),

            ExportColumn::make('keterangan')->label('Keterangan'),

            ExportColumn::make('no_ref')->label('Nomor Referensi'),

            ExportColumn::make('status')->label('Status'),

            ExportColumn::make('user.name')
                ->label('Dicatat oleh')
                ->formatStateUsing(fn($state, $record) => optional($record->user)->name),

            ExportColumn::make('produk.nama_produk')
                ->label('Nama Produk')
                ->formatStateUsing(fn($state, $record) => optional($record->produk)->nama_produk),

            ExportColumn::make('lokasi.nama_lokasi')
                ->label('Lokasi')
                ->formatStateUsing(fn($state, $record) => optional($record->lokasi)->nama_lokasi),

            ExportColumn::make('created_at')->label('Dibuat Pada'),
            ExportColumn::make('updated_at')->label('Diperbarui Pada'),
            ExportColumn::make('created_by')->label('Dibuat Oleh'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export mutasi selesai dengan total '
            . number_format($export->successful_rows) . ' '
            . str('baris')->plural($export->successful_rows) . ' berhasil diekspor.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failed) . ' baris gagal diekspor.';
        }

        return $body;
    }

    public function getJobQueue(): ?string
    {
        return 'excel';
    }

    public function getJobConnection(): ?string
    {
        return config('queue.default');
    }

    public function getFileDisk(): string
    {
        return 'public';
    }

    public function getChunkSize(): ?int
    {
        return 1000;
    }
}
