<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreMutasiRequest;
use App\Http\Requests\UpdateMutasiRequest;
use App\Models\Mutasi;
use App\Models\Produk;
use App\Models\Lokasi;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class MutasiController extends BaseApiController
{
    public function index()
    {
        $mutasi = Mutasi::with('user', 'produk', 'lokasi')->get();
        return $this->success($mutasi, 'Daftar seluruh mutasi');
    }

    public function store(StoreMutasiRequest $request)
    {
        $data = $request->validated();
        $user = auth()->user();

        $mutasi = Mutasi::create([
            ...$data,
            'status' => 'pending',
            'user_id' => $data['user_id'] ?? $user->id,
            'created_by' => $user->id,
        ])->load('produk', 'lokasi', 'user');

        return $this->success($mutasi, 'Mutasi berhasil dicatat dan menunggu approval', 201);
    }

    public function approve(Mutasi $mutasi)
    {
        if ($mutasi->status !== 'pending') {
            return $this->error('Mutasi sudah diproses.', 400);
        }

        $produk = $mutasi->produk;
        $lokasi = $mutasi->lokasi;

        $stokSekarang = $produk->lokasi()->where('lokasi_id', $lokasi->id)->first()?->pivot->stok ?? 0;
        $stokBaru = $mutasi->jenis_mutasi === 'masuk'
            ? $stokSekarang + $mutasi->jumlah
            : $stokSekarang - $mutasi->jumlah;

        if ($stokBaru < 0) {
            return $this->error('Stok tidak cukup untuk disetujui.', 422);
        }

        $produk->lokasi()->syncWithoutDetaching([
            $lokasi->id => ['stok' => $stokBaru]
        ]);

        $mutasi->update(['status' => 'approved']);

        return $this->success($mutasi->fresh(), 'Mutasi berhasil disetujui dan stok diperbarui');
    }

    public function cancel(Mutasi $mutasi)
    {
        if ($mutasi->status !== 'pending') {
            return $this->error('Mutasi tidak bisa dibatalkan', 400);
        }

        $mutasi->update(['status' => 'cancelled']);

        return $this->success($mutasi, 'Mutasi berhasil dibatalkan');
    }

    public function show(Mutasi $mutasi)
    {
        $mutasi->load('produk', 'lokasi', 'user');
        return $this->success($mutasi->load('produk', 'lokasi', 'user'), 'Detail mutasi');
    }

    public function update(UpdateMutasiRequest $request, Mutasi $mutasi)
    {
        return $this->error('Mutasi tidak bisa diupdate. Silakan hapus dan buat ulang.', 405);
    }

    public function destroy(Mutasi $mutasi)
    {
        return $this->error('Mutasi tidak bisa dihapus demi menjaga histori.', 405);
    }
}
