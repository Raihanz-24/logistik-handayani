<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreMutasiRequest;
use App\Http\Requests\UpdateMutasiRequest;
use App\Models\Mutasi;
use App\Services\MutasiStockService;

class MutasiController extends BaseApiController
{
    public function index()
    {
        $mutasi = Mutasi::with('user', 'barang', 'lokasi', 'lokasiTujuan')->get();

        return $this->success($mutasi, 'Daftar seluruh mutasi');
    }

    public function store(StoreMutasiRequest $request)
    {
        $data = $request->validated();
        $user = auth()->user();

        $mutasi = Mutasi::create([
            ...$data,
            'status' => 'pending',
            'user_id' => $user->id,
            'created_by' => $user->id,
        ])->load('barang', 'lokasi', 'lokasiTujuan', 'user');

        return $this->success($mutasi, 'Mutasi berhasil dicatat dan menunggu approval', 201);
    }

    public function approve(Mutasi $mutasi, MutasiStockService $stockService)
    {
        if ($mutasi->status !== 'pending') {
            return $this->error('Mutasi sudah diproses.', 400);
        }

        if (! auth()->user()?->can('approve', $mutasi)) {
            return $this->error('Akun Anda tidak memiliki akses untuk approve mutasi.', 403);
        }

        try {
            $approved = $stockService->approve($mutasi, (int) auth()->id());

            return $this->success($approved, 'Mutasi berhasil disetujui dan stok gudang diperbarui');
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
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
        return $this->success(
            $mutasi->load('barang', 'lokasi', 'lokasiTujuan', 'user'),
            'Detail mutasi',
        );
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
