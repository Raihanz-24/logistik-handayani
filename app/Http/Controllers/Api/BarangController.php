<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Models\Barang;

class BarangController extends BaseApiController
{
    public function index()
    {
        $barangs = Barang::with('lokasi', 'kategoriBarangs')->get();

        return $this->success($barangs, 'Daftar barang');
    }

    public function store(StoreBarangRequest $request)
    {
        $data = $request->validated();

        $barang = Barang::create([
            'nama_barang' => $data['nama_barang'],
            'kode_barang' => $data['kode_barang'],
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
        return $this->success($barang->load('lokasi', 'kategoriBarangs'), 'Detail barang');
    }

    public function update(UpdateBarangRequest $request, Barang $barang)
    {
        $data = $request->validated();

        $barang->update($data);

        if (isset($data['kategori_ids'])) {
            $barang->kategoriBarangs()->sync($data['kategori_ids']);
        }

        return $this->success($barang->load('kategoriBarangs'), 'Barang berhasil diupdate');
    }

    public function destroy(Barang $barang)
    {
        $barang->delete();

        return $this->success(null, 'Barang berhasil dihapus');
    }

    public function history(Barang $barang)
    {
        $mutasi = $barang->mutasi()->with('lokasi', 'user')->get();

        return $this->success($mutasi, 'History mutasi untuk barang: '.$barang->nama_barang);
    }
}
