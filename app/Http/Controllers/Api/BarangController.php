<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Models\Barang;
use Illuminate\Support\Facades\Gate;

class BarangController extends BaseApiController
{
    public function index()
    {
        Gate::authorize('viewAny', Barang::class);

        $barangs = Barang::with('lokasi', 'kategoriBarangs')->get();

        return $this->success($barangs, 'Daftar barang');
    }

    public function store(StoreBarangRequest $request)
    {
        Gate::authorize('create', Barang::class);

        $data = $request->validated();

        $barang = Barang::create([
            'nama_barang' => $data['nama_barang'],
            'satuan' => $data['satuan'],
            'deskripsi' => $data['deskripsi'] ?? null,
        ]);

        if (isset($data['kategori_ids'])) {
            $barang->kategoriBarangs()->sync($data['kategori_ids']);
        }

        return $this->success($barang->load('kategoriBarangs'), 'Barang berhasil ditambahkan', 201);
    }

    public function show(Barang $barang)
    {
        Gate::authorize('view', $barang);

        return $this->success($barang->load('lokasi', 'kategoriBarangs'), 'Detail barang');
    }

    public function update(UpdateBarangRequest $request, Barang $barang)
    {
        Gate::authorize('update', $barang);

        $data = $request->validated();

        $barang->update($data);

        if (isset($data['kategori_ids'])) {
            $barang->kategoriBarangs()->sync($data['kategori_ids']);
        }

        return $this->success($barang->load('kategoriBarangs'), 'Barang berhasil diupdate');
    }

    public function destroy(Barang $barang)
    {
        Gate::authorize('delete', $barang);

        $barang->delete();

        return $this->success(null, 'Barang berhasil dihapus');
    }

    public function history(Barang $barang)
    {
        Gate::authorize('view', $barang);

        $mutasi = $barang->mutasi()->with('lokasi', 'user')->get();

        return $this->success($mutasi, 'History mutasi untuk barang: '.$barang->nama_barang);
    }
}
