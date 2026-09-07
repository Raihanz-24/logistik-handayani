<?php

namespace App\Services;

use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\User;
use App\Services\Pdf\BelanjaPdfDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BelanjaPdfExportService
{
    /** @param array<string, mixed> $context */
    public function download(array $context, User $user): StreamedResponse
    {
        $report = $this->reportData($context, $user);
        $directory = storage_path('app/temp-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder sementara export tidak dapat dibuat.');
        }

        $path = $directory.'/laporan-belanja-'.bin2hex(random_bytes(8)).'.pdf';
        $pdf = app(BelanjaPdfDocument::class)->render($report['rows'], $report['context']);

        if (file_put_contents($path, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Gagal membuat laporan PDF belanja.');
        }

        return response()->streamDownload(function () use ($path): void {
            try {
                $stream = fopen($path, 'rb');

                if ($stream === false) {
                    throw new RuntimeException('Laporan PDF tidak dapat dibaca.');
                }

                fpassthru($stream);
                fclose($stream);
            } finally {
                @unlink($path);
            }
        }, 'laporan_belanja_'.now('Asia/Jakarta')->format('Ymd_His').'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{rows: array<int, array<string, int|string>>, context: array<string, int|string>}
     */
    public function reportData(array $context, User $user): array
    {
        $query = KalkulatorBelanja::query()
            ->visibleTo($user)
            ->with([
                'pengeluaran.supplier',
                'pengeluaran.items.barang',
                'pengeluaran.notas',
                'pengeluaran.fotoBarangSessions:id',
            ]);

        $month = $this->validMonth($context['month'] ?? null);
        $from = $this->validDate($context['from'] ?? null);
        $to = $this->validDate($context['to'] ?? null);
        $search = Str::limit(trim((string) ($context['search'] ?? '')), 100, '');

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($month) {
            [$year, $monthNumber] = array_map('intval', explode('-', $month));
            $query->whereYear('tanggal', $year)->whereMonth('tanggal', $monthNumber);
        } else {
            $query
                ->when($from, fn (Builder $query, string $date): Builder => $query->whereDate('tanggal', '>=', $date))
                ->when($to, fn (Builder $query, string $date): Builder => $query->whereDate('tanggal', '<=', $date));
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $query) use ($like): void {
                $query
                    ->where('judul', 'like', $like)
                    ->orWhereHas('pengeluaran', fn (Builder $query): Builder => $query
                        ->where('nama_supplier_snapshot', 'like', $like)
                        ->orWhereHas('items', fn (Builder $query): Builder => $query
                            ->where('nama_barang_snapshot', 'like', $like)
                            ->orWhere('kode_barang_snapshot', 'like', $like)));
            });
        }

        $sessions = $query->orderBy('tanggal')->orderBy('id')->get();
        $rows = [];
        $sequence = 1;
        $transactionCount = 0;
        $totalOut = 0;

        foreach ($sessions as $session) {
            foreach ($session->pengeluaran as $expense) {
                $transactionCount++;
                $totalOut += (int) $expense->nominal;
                $receiptCount = $expense->jumlahNota();
                $evidence = $receiptCount.' nota · '.$expense->fotoBarangSessions->count().' folder';

                if ($expense->items->isEmpty()) {
                    $rows[] = $this->row(
                        $sequence++,
                        $session,
                        $expense,
                        'Detail barang transaksi lama belum dicatat',
                        '-',
                        '-',
                        (int) $expense->nominal,
                        $evidence,
                    );

                    continue;
                }

                foreach ($expense->items as $item) {
                    $quantity = rtrim(rtrim(number_format((float) $item->jumlah, 3, ',', '.'), '0'), ',')
                        .' '.($item->satuan_snapshot ?: '');
                    $itemNote = collect([$expense->keterangan, $item->keterangan])->filter()->implode(' · ');

                    $rows[] = $this->row(
                        $sequence++,
                        $session,
                        $expense,
                        $item->kode_barang_snapshot.' '.$item->namaBarang(),
                        trim($quantity),
                        self::rupiah((int) $item->harga_satuan),
                        (int) $item->subtotal,
                        $evidence,
                        $itemNote,
                    );
                }
            }
        }

        return [
            'rows' => $rows,
            'context' => [
                'period' => $this->periodLabel($month, $from, $to),
                'search' => $search,
                'session_count' => $sessions->count(),
                'transaction_count' => $transactionCount,
                'total_out' => $totalOut,
                'generated_at' => now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i'),
            ],
        ];
    }

    /** @return array<string, int|string> */
    private function row(
        int $sequence,
        KalkulatorBelanja $session,
        PengeluaranBelanja $expense,
        string $item,
        string $quantity,
        string $price,
        int $subtotal,
        string $evidence,
        ?string $note = null,
    ): array {
        return [
            'sequence' => $sequence,
            'date' => $session->tanggal->format('d/m/Y'),
            'session' => $session->judul,
            'supplier' => $expense->namaSupplier(),
            'item' => $item,
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => self::rupiah($subtotal),
            'note' => $note ?: ($expense->keterangan ?: '-'),
            'evidence' => $evidence,
        ];
    }

    private function validMonth(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function periodLabel(?string $month, ?string $from, ?string $to): string
    {
        if ($month) {
            return Carbon::createFromFormat('!Y-m', $month)->locale('id')->translatedFormat('F Y');
        }

        return match (true) {
            filled($from) && filled($to) => Carbon::parse($from)->format('d/m/Y').' - '.Carbon::parse($to)->format('d/m/Y'),
            filled($from) => 'Mulai '.Carbon::parse($from)->format('d/m/Y'),
            filled($to) => 'Sampai '.Carbon::parse($to)->format('d/m/Y'),
            default => 'Semua periode',
        };
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
