<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menampilkan stok sebuah barang per gudang.
 * READ-ONLY.
 */
class CekStokTool extends AiTool
{
    public function name(): string
    {
        return 'cek_stok';
    }

    public function description(): string
    {
        return 'Melihat stok sebuah barang (total, baik, rusak, hilang) di tiap gudang. '
            .'Butuh barang_id (dapat dari cari_barang). Gunakan sebelum mutasi keluar/perubahan kondisi '
            .'untuk memastikan stok mencukupi.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'barang_id' => [
                    'type' => 'integer',
                    'description' => 'ID barang (dari cari_barang).',
                ],
                'lokasi_id' => [
                    'type' => 'integer',
                    'description' => 'Batasi ke satu gudang tertentu. Opsional.',
                ],
            ],
            'required' => ['barang_id'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $barangId = (int) ($arguments['barang_id'] ?? 0);

        if ($barangId <= 0) {
            throw new ToolException('barang_id wajib berupa angka positif.');
        }

        $barang = Barang::query()->select(['id', 'nama_barang', 'kode_barang', 'satuan', 'is_barang_pasar'])->find($barangId);

        if (! $barang) {
            return $this->limitPayload([
                'ditemukan' => false,
                'pesan' => 'Barang tidak ditemukan. Coba cari ulang dengan cari_barang.',
            ]);
        }

        if ($barang->isBarangPasar()) {
            return $this->limitPayload([
                'ditemukan' => true,
                'barang_pasar' => true,
                'barang' => [
                    'barang_id' => (int) $barang->getKey(),
                    'nama_barang' => (string) $barang->nama_barang,
                    'kode_barang' => (string) $barang->kode_barang,
                    'satuan' => (string) $barang->satuan,
                ],
                'pesan' => 'Barang ini berkategori pasar: tidak disimpan sebagai stok gudang, jadi tidak punya saldo stok. Catat kebutuhan di transaksi belanja.',
                'total_semua_gudang' => 0,
                'stok_per_gudang' => [],
            ]);
        }

        $rows = DB::table('barang_lokasi')
            ->join('lokasis', 'lokasis.id', '=', 'barang_lokasi.lokasi_id')
            ->leftJoin('posisi_raks', 'posisi_raks.id', '=', 'barang_lokasi.posisi_rak_id')
            ->where('lokasis.jenis_lokasi', Lokasi::JENIS_GUDANG)
            ->where('barang_lokasi.barang_id', $barangId)
            ->when(
                (int) ($arguments['lokasi_id'] ?? 0) > 0,
                fn ($q) => $q->where('barang_lokasi.lokasi_id', (int) $arguments['lokasi_id']),
            )
            ->orderBy('lokasis.nama_lokasi')
            ->select([
                'lokasis.id as lokasi_id',
                'lokasis.nama_lokasi',
                'barang_lokasi.stok',
                'barang_lokasi.stok_baik',
                'barang_lokasi.stok_rusak',
                'barang_lokasi.stok_hilang',
                'posisi_raks.kode as kode_rak',
            ])
            ->limit(50)
            ->get()
            ->map(fn ($row): array => [
                'lokasi_id' => (int) $row->lokasi_id,
                'nama_lokasi' => (string) $row->nama_lokasi,
                'stok_total' => (int) $row->stok,
                'stok_baik' => (int) $row->stok_baik,
                'stok_rusak' => (int) $row->stok_rusak,
                'stok_hilang' => (int) $row->stok_hilang,
                'kode_rak' => $row->kode_rak !== null ? (string) $row->kode_rak : null,
            ])
            ->all();

        return $this->limitPayload([
            'ditemukan' => true,
            'barang' => [
                'barang_id' => (int) $barang->getKey(),
                'nama_barang' => (string) $barang->nama_barang,
                'kode_barang' => (string) $barang->kode_barang,
                'satuan' => (string) $barang->satuan,
            ],
            'total_semua_gudang' => array_sum(array_column($rows, 'stok_total')),
            'stok_per_gudang' => $rows,
        ]);
    }
}
