<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreMutasiRequest;
use App\Http\Requests\UpdateMutasiRequest;
use App\Models\Mutasi;
use App\Services\MutasiDataService;
use App\Services\MutasiStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MutasiController extends BaseApiController
{
    public function index()
    {
        Gate::authorize('viewAny', Mutasi::class);

        $mutasi = Mutasi::with('user', 'barang', 'supplier', 'lokasi', 'lokasiTujuan')->get();

        return $this->success($mutasi, 'Daftar seluruh mutasi');
    }

    public function store(StoreMutasiRequest $request, MutasiDataService $dataService)
    {
        Gate::authorize('create', Mutasi::class);

        try {
            $data = $dataService->prepare($request->validated());
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        $user = auth()->user();

        $mutasi = Mutasi::create([
            ...$data,
            'status' => 'pending',
            'user_id' => $user->id,
            'created_by' => $user->id,
        ])->load('barang', 'supplier', 'lokasi', 'lokasiTujuan', 'user');

        return $this->success($mutasi, 'Mutasi berhasil dicatat dan menunggu approval', 201);
    }

    public function approve(Mutasi $mutasi, MutasiStockService $stockService)
    {
        if ($mutasi->status !== 'pending') {
            return $this->error('Mutasi sudah diproses.', 400);
        }

        Gate::authorize('approve', $mutasi);

        try {
            $approved = $stockService->approve($mutasi, (int) auth()->id());

            return $this->success($approved, 'Mutasi berhasil disetujui dan stok gudang diperbarui');
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function cancel(Request $request, Mutasi $mutasi)
    {
        Gate::authorize('cancel', $mutasi);

        if ($mutasi->status !== 'pending') {
            return $this->error('Mutasi tidak bisa dibatalkan', 400);
        }

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:255'],
        ], [
            'cancel_reason.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        $mutasi->update([
            'status' => 'cancelled',
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => trim($data['cancel_reason']),
        ]);

        return $this->success($mutasi, 'Mutasi berhasil dibatalkan');
    }

    public function show(Mutasi $mutasi)
    {
        Gate::authorize('view', $mutasi);

        return $this->success(
            $mutasi->load('barang', 'supplier', 'lokasi', 'lokasiTujuan', 'user'),
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
