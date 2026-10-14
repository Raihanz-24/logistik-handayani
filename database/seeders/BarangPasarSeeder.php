<?php

namespace Database\Seeders;

use App\Models\Barang;
use Illuminate\Database\Seeder;

/**
 * Seeder daftar Barang Pasar.
 *
 * Sumber data: database/seeders/data/barang-pasar.php
 * Isi file tersebut dengan nama-nama barang pasar, lalu jalankan:
 *
 *   php artisan db:seed --class="Database\Seeders\BarangPasarSeeder"
 *
 * Sifat: idempotent (aman dijalankan berulang). Barang dicocokkan berdasarkan
 * nama, tidak akan digandakan. Semua barang dibuat dengan toggle
 * "Barang Pasar" AKTIF (is_barang_pasar = true) dan satuan "-" bila tidak diisi.
 *
 * TIDAK dipanggil otomatis oleh DatabaseSeeder agar tidak mengubah data produksi.
 */
class BarangPasarSeeder extends Seeder
{
    /**
     * Path file daftar barang pasar. Bisa diubah (mis. dari test) sebelum run().
     */
    public ?string $dataFile = null;

    private function dataFilePath(): string
    {
        return $this->dataFile ?? database_path('seeders/data/barang-pasar.php');
    }

    public function run(): void
    {
        $path = $this->dataFilePath();

        if (! is_file($path)) {
            $this->command?->error("File daftar barang pasar tidak ditemukan: {$path}");

            return;
        }

        /** @var array<int, string|array<string, mixed>> $items */
        $items = require $path;

        if (! is_array($items)) {
            $this->command?->error('Isi file barang-pasar.php harus mengembalikan array.');

            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $attributes = $this->normalize($item);

            if ($attributes === null) {
                $skipped++;

                continue;
            }

            $barang = Barang::query()->where('nama_barang', $attributes['nama_barang'])->first();

            if (! $barang) {
                Barang::query()->create([
                    'nama_barang' => $attributes['nama_barang'],
                    'satuan' => $attributes['satuan'],
                    'ai_alias' => $attributes['ai_alias'],
                    'is_barang_pasar' => true,
                ]);

                $created++;

                continue;
            }

            $changes = [];

            // Pastikan terdaftar sebagai barang pasar (additive, tidak menghapus data lain).
            if (! $barang->is_barang_pasar) {
                $changes['is_barang_pasar'] = true;
            }

            // Isi satuan "-" hanya bila saat ini kosong.
            if (blank($barang->satuan) && filled($attributes['satuan'])) {
                $changes['satuan'] = $attributes['satuan'];
            }

            // Isi alias AI hanya bila saat ini kosong.
            if (blank($barang->ai_alias) && filled($attributes['ai_alias'])) {
                $changes['ai_alias'] = $attributes['ai_alias'];
            }

            if ($changes !== []) {
                $barang->update($changes);
                $updated++;

                continue;
            }

            $skipped++;
        }

        $this->command?->info("Barang pasar: {$created} dibuat, {$updated} diperbarui, {$skipped} dilewati (sudah ada).");
    }

    /**
     * Ubah satu entri daftar menjadi atribut yang siap disimpan.
     *
     * @param  string|array<string, mixed>  $item
     * @return array{nama_barang: string, satuan: string, ai_alias: ?string}|null
     */
    private function normalize(string|array $item): ?array    {
        if (is_string($item)) {
            $name = trim($item);

            return $name === ''
                ? null
                : ['nama_barang' => $name, 'satuan' => '-', 'ai_alias' => null];
        }

        if (! is_array($item)) {
            return null;
        }

        $name = trim((string) ($item['nama_barang'] ?? $item['nama'] ?? ''));

        if ($name === '') {
            return null;
        }

        $satuan = trim((string) ($item['satuan'] ?? ''));
        $alias = trim((string) ($item['ai_alias'] ?? ''));

        return [
            'nama_barang' => $name,
            'satuan' => $satuan !== '' ? $satuan : '-',
            'ai_alias' => $alias !== '' ? $alias : null,
        ];
    }
}
