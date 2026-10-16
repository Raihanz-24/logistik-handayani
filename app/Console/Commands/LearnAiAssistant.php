<?php

namespace App\Console\Commands;

use App\Services\Ai\AiSupplierBarangStatsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Membangun ulang agregat afinitas supplier <-> barang dari histori transaksi
 * belanja. Dipakai agar Asisten AI tahu "biasanya order apa dari supplier X".
 *
 * Idempotent & aman: data agregat sepenuhnya turunan dari transaksi yang ada,
 * dan hanya menyentuh tabel ai_supplier_barang_stats.
 */
class LearnAiAssistant extends Command
{
    protected $signature = 'ai:learn {--show : Tampilkan ringkasan top barang per supplier}';

    protected $description = 'Membangun ulang agregat afinitas supplier<->barang untuk Asisten AI (aman diulang).';

    public function handle(AiSupplierBarangStatsService $stats): int
    {
        try {
            $count = $stats->refreshAll();
            $this->info("Selesai. Agregat dibangun untuk {$count} supplier.");

            if ($this->option('show')) {
                $this->printSummary($stats);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Gagal membangun agregat: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function printSummary(AiSupplierBarangStatsService $stats): void
    {
        $suppliers = \App\Models\Supplier::query()
            ->whereIn('id', \App\Models\AiSupplierBarangStat::query()->distinct()->pluck('supplier_id'))
            ->orderBy('nama_supplier')
            ->get(['id', 'nama_supplier']);

        foreach ($suppliers as $supplier) {
            $suggestions = $stats->suggestions((int) $supplier->id, 5);

            if ($suggestions === []) {
                continue;
            }

            $this->newLine();
            $this->line('<fg=green>'.$supplier->nama_supplier.'</>');

            foreach ($suggestions as $item) {
                $harga = $item['harga_terakhir'] ? 'Rp'.number_format((int) $item['harga_terakhir'], 0, ',', '.') : '-';
                $satuan = $item['satuan_terakhir'] ?: '-';
                $this->line(sprintf(
                    '  - %s (%dx, %s, %s)',
                    $item['nama_barang'],
                    $item['frekuensi'],
                    $satuan,
                    $harga,
                ));
            }
        }
    }
}
