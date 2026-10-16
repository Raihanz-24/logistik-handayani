<?php

namespace App\Filament\Pages;

use App\Models\AiSearchLog;
use App\Models\AiUsage;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiSupplierBarangStatsService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Halaman penilaian "Kecerdasan AI" (read-only).
 *
 * Menampilkan skor deterministik seberapa baik Asisten AI:
 * - mengenali barang (dari log pencarian: berapa yang langsung dapat pilihan
 *   terbaik vs ambigu), dan
 * - mengenali POLA URUTAN item per supplier (dari agregat afinitas).
 *
 * Tidak ada aksi tulis di sini; murni untuk transparansi & evaluasi.
 */
class KecerdasanAi extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Kecerdasan AI';

    protected static ?string $title = 'Kecerdasan AI — Penilaian';

    protected static ?int $navigationSort = 906;

    protected static ?string $slug = 'kecerdasan-ai';

    protected static string $view = 'filament.pages.kecerdasan-ai';

    protected ?string $heading = '';

    protected ?string $maxContentWidth = 'full';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasRole('super_admin')
            || $user->can('view_any_kalkulator_belanja')
            || $user->can('view_any_barang');
    }

    /**
     * Ringkasan metrik pengenalan barang.
     *
     * @return array{total: int, tepat: int, ambigu: int, akurasi: float}
     */
    public function recognitionStats(): array
    {
        if (! Schema::hasTable('ai_search_logs')) {
            return ['total' => 0, 'tepat' => 0, 'ambigu' => 0, 'akurasi' => 0.0];
        }

        $total = AiSearchLog::query()->count();

        if ($total === 0) {
            return ['total' => 0, 'tepat' => 0, 'ambigu' => 0, 'akurasi' => 0.0];
        }

        $tepat = AiSearchLog::query()->whereNotNull('pilihan_terbaik_barang_id')->count();
        $ambigu = AiSearchLog::query()->where('ambigu', true)->count();

        return [
            'total' => $total,
            'tepat' => $tepat,
            'ambigu' => $ambigu,
            'akurasi' => round(($tepat / max(1, $total)) * 100, 1),
        ];
    }

    /**
     * Metrik "kecerdasan lokal": berapa giliran yang ditangani tanpa API
     * (hemat token) dan berapa yang diteruskan ke model.
     *
     * @return array{lokal: int, model: int, hemat: float}
     */
    public function localStats(): array
    {
        if (! Schema::hasTable('ai_usages')) {
            return ['lokal' => 0, 'model' => 0, 'hemat' => 0.0];
        }

        $lokal = AiUsage::query()->where('provider', 'like', 'Lokal%')->count();
        $model = AiUsage::query()->where('provider', 'not like', 'Lokal%')->count();
        $total = $lokal + $model;

        return [
            'lokal' => $lokal,
            'model' => $model,
            'hemat' => $total > 0 ? round(($lokal / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Daftar supplier beserta pola urutan yang dipelajari + kekuatannya.
     *
     * @return array<int, array{nama: string, pola: array<int, array<string, mixed>>, keyakinan: float, transaksi: int}>
     */
    public function supplierPatterns(): array
    {
        $stats = app(AiSupplierBarangStatsService::class);

        if (! $stats->available()) {
            return [];
        }

        $suppliers = Supplier::query()
            ->whereIn('id', function ($query): void {
                $query->select('supplier_id')->from('ai_supplier_barang_stats');
            })
            ->orderBy('nama_supplier')
            ->get(['id', 'nama_supplier']);

        return $suppliers->map(function (Supplier $supplier) use ($stats): array {
            $pola = $stats->orderPattern((int) $supplier->getKey(), 20);
            $frekuensi = collect($pola)->sum('frekuensi');

            return [
                'nama' => (string) $supplier->nama_supplier,
                'pola' => $pola,
                'keyakinan' => $this->confidence($pola),
                'transaksi' => (int) $frekuensi,
            ];
        })->all();
    }

    /**
     * Keyakinan pola 0..100: makin konsisten (frekuensi tinggi & banyak item
     * berulang) makin tinggi. Heuristik transparan, bukan probabilitas.
     *
     * @param  array<int, array<string, mixed>>  $pola
     */
    private function confidence(array $pola): float
    {
        if ($pola === []) {
            return 0.0;
        }

        $count = count($pola);
        $rataFrekuensi = collect($pola)->avg('frekuensi') ?: 0;

        // Komponen: cukup item pola + rata-rata kemunculan.
        $skorItem = min(1.0, $count / 5);
        $skorFrekuensi = min(1.0, $rataFrekuensi / 4);

        return round((($skorItem + $skorFrekuensi) / 2) * 100, 1);
    }

    /**
     * Pencarian terbaru untuk ditampilkan (yang ambigu lebih menarik untuk ditinjau).
     *
     * @return Collection<int, AiSearchLog>
     */
    public function recentSearches()
    {
        if (! Schema::hasTable('ai_search_logs')) {
            return collect();
        }

        return AiSearchLog::query()
            ->with('barang:id,nama_barang')
            ->orderByDesc('ambigu')
            ->orderByDesc('id')
            ->limit(15)
            ->get();
    }

    /**
     * Kata kunci yang paling sering ambigu (kandidat untuk ditambah alias).
     *
     * @return Collection<int, AiSearchLog>
     */
    public function ambiguousKeywords()
    {
        if (! Schema::hasTable('ai_search_logs')) {
            return collect();
        }

        return AiSearchLog::query()
            ->selectRaw('kata_kunci, COUNT(*) as jumlah')
            ->where('ambigu', true)
            ->whereNotNull('kata_kunci')
            ->groupBy('kata_kunci')
            ->orderByDesc('jumlah')
            ->limit(10)
            ->get();
    }
}
