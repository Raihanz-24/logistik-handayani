<?php

namespace App\Filament\Exports;

use App\Models\Mutasi;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class MutasiExporter extends Exporter
{
    protected static ?string $model = Mutasi::class;

    public static function modifyQuery(Builder $query): Builder
    {
        $t = $query->getModel()->getTable(); // 'mutasis'

        return $query
            ->select([
                "{$t}.id",
                "{$t}.tanggal",
                "{$t}.produk_id",
                "{$t}.jenis_mutasi",
                "{$t}.jumlah",
                "{$t}.lokasi_id",
                "{$t}.lokasi_tujuan_id",
                "{$t}.stok_akhir",
                "{$t}.status",
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
            // 1) Tanggal
            ExportColumn::make('tanggal')
                ->label('Tanggal')
                ->formatStateUsing(function ($state, $record) {
                    $v = $record->tanggal;

                    if ($v instanceof CarbonInterface) {
                        // buat human-friendly dan konsisten
                        return $v->format('Y-m-d');
                    }

                    // jika string sudah 'YYYY-mm-dd ...', ambil date saja
                    if (is_string($v) && $v !== '') {
                        return substr($v, 0, 10);
                    }

                    return null;
                }),

            // 2) Nama Produk
            ExportColumn::make('produk.nama_produk')
                ->label('Nama Produk')
                ->formatStateUsing(fn ($state, $record) => optional($record->produk)->nama_produk),

            // 3) Jenis Mutasi
            ExportColumn::make('jenis_mutasi')
                ->label('Jenis Mutasi')
                ->formatStateUsing(fn ($state) => $state === 'keluar' ? 'Keluar' : 'Masuk'),

            // 4) Jumlah
            ExportColumn::make('jumlah')
                ->label('Jumlah')
                ->formatStateUsing(fn ($state) => is_numeric($state) ? (int) $state : $state),

            // 5) Asal
            ExportColumn::make('asal_display')
                ->label('Asal')
                ->state(function (Mutasi $record) {
                    // masuk = dari luar (tampilkan strip)
                    if ($record->jenis_mutasi === 'masuk') {
                        return '-';
                    }

                    // keluar = gudang asal (lokasi_id)
                    return $record->lokasi?->nama_lokasi ?? '-';
                }),

            // 6) Tujuan
            ExportColumn::make('tujuan_display')
                ->label('Tujuan')
                ->state(function (Mutasi $record) {
                    // masuk = gudang tujuan (lokasi_id)
                    if ($record->jenis_mutasi === 'masuk') {
                        return $record->lokasi?->nama_lokasi ?? '-';
                    }

                    // keluar = lokasi_tujuan_id
                    return $record->lokasiTujuan?->nama_lokasi ?? '-';
                }),

            // 7) Stok Akhir
            ExportColumn::make('stok_akhir')
                ->label('Stok Akhir')
                ->formatStateUsing(fn ($state) => is_numeric($state) ? (int) $state : ($state ?? '-')),

            // 8) Status
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => match ($state) {
                    'approved' => 'Disetujui',
                    'cancelled' => 'Dibatalkan',
                    default => 'Pending',
                }),
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
