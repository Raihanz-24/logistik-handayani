<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreLokasiRequest;
use App\Http\Requests\UpdateLokasiRequest;
use App\Models\Lokasi;

class LokasiController extends BaseApiController
{
    public function index()
    {
        $lokasi = Lokasi::withCount('barang')->get();

        return $this->success($lokasi, 'Daftar lokasi');
    }

    public function store(StoreLokasiRequest $request)
    {
        $lokasi = Lokasi::create($request->validated());

        return $this->success($lokasi, 'Lokasi berhasil ditambahkan', 201); // Created
    }

    public function show(Lokasi $lokasi)
    {
        return $this->success($lokasi->load('barang'), 'Detail lokasi');
    }

    public function update(UpdateLokasiRequest $request, Lokasi $lokasi)
    {
        $lokasi->update($request->validated());

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
