<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\PosisiRak;
use App\Models\RakGudang;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RakGudangService
{
    /**
     * @param  array<int, array{nomor_rak?: int|string, jumlah_tingkat?: int|string}>  $configuration
     */
    public function sync(Lokasi $lokasi, array $configuration): void
    {
        if (! $lokasi->isGudang() || ! $lokasi->menggunakan_rak) {
            $this->disableAll($lokasi);
            $lokasi->updateQuietly([
                'menggunakan_rak' => $lokasi->isGudang() ? $lokasi->menggunakan_rak : false,
                'konfigurasi_rak' => null,
            ]);

            return;
        }

        $normalized = collect(array_values($configuration))
            ->map(function (array $rack, int $index): array {
                $levels = (int) ($rack['jumlah_tingkat'] ?? 0);

                if ($levels < 1 || $levels > 50) {
                    throw new RuntimeException('Setiap rak wajib memiliki 1 sampai 50 tingkat.');
                }

                return [
                    'nomor_rak' => $index + 1,
                    'jumlah_tingkat' => $levels,
                ];
            })
            ->all();

        if ($normalized === [] || count($normalized) > 50) {
            throw new RuntimeException('Gudang yang menggunakan rak wajib memiliki 1 sampai 50 rak.');
        }

        DB::transaction(function () use ($lokasi, $normalized): void {
            foreach ($normalized as $rackData) {
                $rack = RakGudang::query()->updateOrCreate(
                    [
                        'lokasi_id' => $lokasi->id,
                        'nomor_rak' => $rackData['nomor_rak'],
                    ],
                    [
                        'jumlah_tingkat' => $rackData['jumlah_tingkat'],
                        'aktif' => true,
                    ],
                );

                for ($level = 1; $level <= $rackData['jumlah_tingkat']; $level++) {
                    PosisiRak::query()->updateOrCreate(
                        [
                            'rak_gudang_id' => $rack->id,
                            'nomor_tingkat' => $level,
                        ],
                        [
                            'lokasi_id' => $lokasi->id,
                            'kode' => self::code($rackData['nomor_rak'], $level),
                            'aktif' => true,
                        ],
                    );
                }

                $rack->posisi()
                    ->where('nomor_tingkat', '>', $rackData['jumlah_tingkat'])
                    ->get()
                    ->each(fn (PosisiRak $position) => $this->deactivatePosition($position));
            }

            RakGudang::query()
                ->where('lokasi_id', $lokasi->id)
                ->where('nomor_rak', '>', count($normalized))
                ->get()
                ->each(function (RakGudang $rack): void {
                    $rack->posisi->each(fn (PosisiRak $position) => $this->deactivatePosition($position));
                    $rack->update(['aktif' => false]);
                });

            $lokasi->updateQuietly(['konfigurasi_rak' => $normalized]);
        });
    }

    public static function code(int $rackNumber, int $levelNumber): string
    {
        return 'RK'.$rackNumber.'-'.str_pad((string) $levelNumber, 2, '0', STR_PAD_LEFT);
    }

    private function disableAll(Lokasi $lokasi): void
    {
        $hasStock = DB::table('barang_lokasi')
            ->where('lokasi_id', $lokasi->id)
            ->where('stok', '>', 0)
            ->exists();

        if ($hasStock && $lokasi->raks()->where('aktif', true)->exists()) {
            throw new RuntimeException('Rak tidak dapat dinonaktifkan karena gudang masih memiliki stok.');
        }

        $lokasi->raks()->with('posisi')->get()->each(function (RakGudang $rack): void {
            $rack->posisi->each(fn (PosisiRak $position) => $this->deactivatePosition($position));
            $rack->update(['aktif' => false]);
        });
    }

    private function deactivatePosition(PosisiRak $position): void
    {
        $hasStock = DB::table('barang_lokasi')
            ->where('posisi_rak_id', $position->id)
            ->where('stok', '>', 0)
            ->exists();

        if ($hasStock) {
            throw new RuntimeException("Posisi {$position->kode} tidak dapat dihapus karena masih menyimpan stok.");
        }

        DB::table('barang_lokasi')
            ->where('posisi_rak_id', $position->id)
            ->where('stok', 0)
            ->update(['posisi_rak_id' => null]);

        $position->update(['aktif' => false]);
    }
}
