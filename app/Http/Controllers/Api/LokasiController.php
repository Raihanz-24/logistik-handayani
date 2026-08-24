<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreLokasiRequest;
use App\Http\Requests\UpdateLokasiRequest;
use App\Models\Lokasi;
use App\Services\RakGudangService;
use Illuminate\Support\Facades\DB;

class LokasiController extends BaseApiController
{
    public function index()
    {
        $lokasi = Lokasi::withCount('barang')->get();

        return $this->success($lokasi, 'Daftar lokasi');
    }

    public function store(StoreLokasiRequest $request)
    {
        $data = $request->validated();
        $lokasi = DB::transaction(function () use ($data): Lokasi {
            $record = Lokasi::query()->create($data);
            app(RakGudangService::class)->sync($record, $data['konfigurasi_rak'] ?? []);

            return $record;
        });

        return $this->success($lokasi, 'Lokasi berhasil ditambahkan', 201); // Created
    }

    public function show(Lokasi $lokasi)
    {
        return $this->success($lokasi->load('barang'), 'Detail lokasi');
    }

    public function update(UpdateLokasiRequest $request, Lokasi $lokasi)
    {
        $data = $request->validated();
        DB::transaction(function () use ($lokasi, $data): void {
            $lokasi->update($data);
            app(RakGudangService::class)->sync($lokasi->fresh(), $data['konfigurasi_rak'] ?? $lokasi->konfigurasi_rak ?? []);
        });

        return $this->success($lokasi, 'Lokasi berhasil diperbarui');
    }

    public function destroy(Lokasi $lokasi)
    {
        if ($lokasi->barang()->exists() || $lokasi->mutasi()->exists() || $lokasi->mutasiTujuan()->exists()) {
            return $this->error('Lokasi tidak dapat dihapus karena sudah memiliki stok atau riwayat mutasi.', 422);
        }

        $lokasi->delete();

        return $this->success(null, 'Lokasi berhasil dihapus');
    }
}
