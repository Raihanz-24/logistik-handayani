<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan pemakaian token Asisten AI per hari (untuk memantau anggaran).
 *
 * Contoh:
 *   php artisan ai:usage            # 14 hari terakhir
 *   php artisan ai:usage --days=30  # 30 hari terakhir
 */
class AiUsageReport extends Command
{
    protected $signature = 'ai:usage {--days=14 : Jumlah hari terakhir}';

    protected $description = 'Tampilkan pemakaian token Asisten AI per hari.';

    public function handle(): int
    {
        if (! Schema::hasTable('ai_usages')) {
            $this->warn('Tabel ai_usages belum ada. Jalankan: php artisan migrate');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $start = CarbonImmutable::today()->subDays($days - 1)->startOfDay();
        $budget = max(1, (int) config('ai.budget.daily_tokens', 1000000));

        $rows = AiUsage::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as hari, COUNT(*) as req, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, SUM(total_tokens) as total')
            ->groupBy('hari')
            ->orderByDesc('hari')
            ->get()
            ->keyBy('hari');

        $table = [];
        $grand = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = CarbonImmutable::today()->subDays($i)->toDateString();
            $row = $rows[$date] ?? null;

            $total = (int) ($row->total ?? 0);
            $grand += $total;
            $persen = round(($total / $budget) * 100, 1);

            $table[] = [
                $date,
                (int) ($row->req ?? 0),
                number_format((int) ($row->prompt ?? 0), 0, ',', '.'),
                number_format((int) ($row->completion ?? 0), 0, ',', '.'),
                number_format($total, 0, ',', '.'),
                $persen.'%',
            ];
        }

        $this->newLine();
        $this->line('<fg=yellow>Anggaran harian:</> '.number_format($budget, 0, ',', '.').' token');
        $this->table(
            ['Tanggal', 'Req', 'Prompt', 'Completion', 'Total', '% anggaran'],
            $table,
        );

        $rata = $days > 0 ? (int) round($grand / $days) : 0;
        $this->line('Total '.$days.' hari: '.number_format($grand, 0, ',', '.').' token · rata-rata '.number_format($rata, 0, ',', '.').'/hari');

        // Proyeksi sederhana: berapa permintaan belanja 35 item yang muat.
        if ($rata > 0) {
            $this->line('Rata-rata pemakaian setara '.number_format((int) round($budget / max(1, $rata)), 0, ',', '.').' hari anggaran per 1 hari pemakaian (kasar).');
        }

        return self::SUCCESS;
    }
}
