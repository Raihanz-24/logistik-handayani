<?php

namespace App\Filament\Exports;

use App\Models\Mutasi;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Entity\Style\StyleBuilder;

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
            // 1) Tanggal (format Indonesia)
            ExportColumn::make('tanggal')
                ->label('Tanggal')
                ->formatStateUsing(function ($state, Mutasi $record) {
                    if (! $record->tanggal) {
                        return null;
                    }

                    // Aman untuk string/date cast
                    return Carbon::parse($record->tanggal)
                        ->locale('id')
                        ->translatedFormat('l, d F Y'); // contoh: Jumat, 03 Januari 2026
                }),

            // 2) Nama Produk
            ExportColumn::make('produk.nama_produk')
                ->label('Nama Produk')
                ->formatStateUsing(fn ($state, Mutasi $record) => $record->produk?->nama_produk),

            // 3) Jenis Mutasi
            ExportColumn::make('jenis_mutasi')
                ->label('Jenis Mutasi')
                ->formatStateUsing(fn ($state, Mutasi $record) => $record->jenis_mutasi === 'keluar' ? 'Keluar' : 'Masuk'),

            // 4) Jumlah (pastikan integer)
            ExportColumn::make('jumlah')
                ->label('Jumlah')
                ->formatStateUsing(fn ($state, Mutasi $record) => (int) ($record->jumlah ?? 0)),

            // 5) Asal
            ExportColumn::make('asal_display')
                ->label('Asal')
                ->state(function (Mutasi $record): string {
                    // masuk dari luar -> "Stok"
                    if ($record->jenis_mutasi === 'masuk') {
                        return 'Stok';
                    }

                    return $record->lokasi?->nama_lokasi ?? '-';
                }),

            // 6) Tujuan
            ExportColumn::make('tujuan_display')
                ->label('Tujuan')
                ->state(function (Mutasi $record): string {
                    // masuk -> gudang tujuan ada di lokasi_id
                    if ($record->jenis_mutasi === 'masuk') {
                        return $record->lokasi?->nama_lokasi ?? '-';
                    }

                    return $record->lokasiTujuan?->nama_lokasi ?? '-';
                }),

            // 7) Stok Awal
            ExportColumn::make('stok_awal')
                ->label('Stok Awal')
                ->formatStateUsing(fn ($state, Mutasi $record) => is_null($record->stok_awal) ? '-' : (int) $record->stok_awal),

            // 8) Stok Akhir
            ExportColumn::make('stok_akhir')
                ->label('Stok Akhir')
                ->formatStateUsing(fn ($state, Mutasi $record) => is_null($record->stok_akhir) ? '-' : (int) $record->stok_akhir),

            // 9) Status (Indonesia)
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state, Mutasi $record) => match ($record->status) {
                    'approved' => 'Disetujui',
                    'cancelled' => 'Dibatalkan',
                    default => 'Pending',
                }),
        ];
    }

    /**
     * Rapikan header biar lebih enak dibaca.
     * (Exporter bawaan hanya support style cell/header, tidak ada title row dan autosize kolom.)
     */
    public function getXlsxHeaderCellStyle(): ?Style
    {
        return (new StyleBuilder())
            ->setFontBold()
            ->setShouldWrapText()
            ->build();
    }

    public function getXlsxCellStyle(): ?Style
    {
        return (new StyleBuilder())
            ->setShouldWrapText()
            ->build();
    }

    public static function getCompletedNotificationTitle(Export $export): string
    {
        return 'Export Riwayat Mutasi Barang Warehouse POH 1 selesai';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export selesai dengan total '
            . number_format($export->successful_rows) . ' '
            . str('baris')->plural($export->successful_rows) . ' berhasil diekspor.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failed) . ' baris gagal diekspor.';
        }

        return $body;
    }

    public function getFileName(Export $export): string
    {
        return 'riwayat_mutasi_warehouse_poh1_' . now()->format('Ymd_His');
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
