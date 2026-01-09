<?php

namespace App\Filament\Exports;

use App\Models\Mutasi;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class MutasiExporter extends Exporter
{
    protected static ?string $model = Mutasi::class;

    public static function modifyQuery(Builder $query): Builder
    {
        $t = $query->getModel()->getTable(); // mutasis

        return $query
            ->select([
                "{$t}.id",
                "{$t}.tanggal",
                "{$t}.jenis_mutasi",
                "{$t}.jumlah",
                "{$t}.status",
                "{$t}.produk_id",
                "{$t}.lokasi_id",
                "{$t}.lokasi_tujuan_id",
                "{$t}.stok_awal",
                "{$t}.stok_akhir",
            ])
            ->with([
                'produk:id,nama_produk',
                'lokasi:id,nama_lokasi',
                'lokasiTujuan:id,nama_lokasi',
            ])
            ->orderBy("{$t}.id", 'desc');
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('tanggal')
                ->label('Tanggal')
                ->formatStateUsing(function ($state, Mutasi $record) {
                    if (! $record->tanggal) return null;

                    return Carbon::parse($record->tanggal)
                        ->locale('id')
                        ->translatedFormat('l, d F Y');
                }),

            ExportColumn::make('produk.nama_produk')
                ->label('Nama Produk')
                ->formatStateUsing(fn ($state, Mutasi $record) => $record->produk?->nama_produk ?? '-'),

            ExportColumn::make('jenis_mutasi')
                ->label('Jenis Mutasi')
                ->formatStateUsing(fn ($state, Mutasi $record) => $record->jenis_mutasi === 'keluar' ? 'Keluar' : 'Masuk'),

            ExportColumn::make('jumlah')
                ->label('Jumlah')
                ->formatStateUsing(fn ($state, Mutasi $record) => (int) ($record->jumlah ?? 0)),

            ExportColumn::make('asal_display')
                ->label('Asal')
                ->state(function (Mutasi $record): string {
                    // masuk dari luar
                    if ($record->jenis_mutasi === 'masuk') {
                        return 'Stok';
                    }

                    return $record->lokasi?->nama_lokasi ?? '-';
                }),

            ExportColumn::make('tujuan_display')
                ->label('Tujuan')
                ->state(function (Mutasi $record): string {
                    // masuk -> tujuan = lokasi_id (gudang itu sendiri)
                    if ($record->jenis_mutasi === 'masuk') {
                        return $record->lokasi?->nama_lokasi ?? '-';
                    }

                    return $record->lokasiTujuan?->nama_lokasi ?? '-';
                }),

            ExportColumn::make('stok_awal')
                ->label('Stok Awal')
                ->formatStateUsing(fn ($state, Mutasi $record) => is_null($record->stok_awal) ? '-' : (int) $record->stok_awal),

            ExportColumn::make('stok_akhir')
                ->label('Stok Akhir')
                ->formatStateUsing(fn ($state, Mutasi $record) => is_null($record->stok_akhir) ? '-' : (int) $record->stok_akhir),

            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state, Mutasi $record) => match ($record->status) {
                    'approved' => 'Disetujui',
                    'cancelled' => 'Dibatalkan',
                    default => 'Pending',
                }),
        ];
    }

    public static function getCompletedNotificationTitle(Export $export): string
    {
        return 'Export Riwayat Mutasi Barang Warehouse POH 1 selesai';
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
