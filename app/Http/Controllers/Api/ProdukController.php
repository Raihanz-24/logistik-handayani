<?php
namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProdukRequest;
use App\Http\Requests\UpdateProdukRequest;
use App\Models\Produk;
use Illuminate\Http\Request;

class ProdukController extends BaseApiController
{
    public function index()
    {
        $produks = Produk::with('lokasi', 'kategoriProduks')->get();
        return $this->success($produks, 'Daftar produk');
    }

    public function store(StoreProdukRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('gambar')) {
            $data['gambar'] = $request->file('gambar')->store('produk', 'public');
        }

        $produk = Produk::create([
            'nama_produk' => $data['nama_produk'],
            'kode_produk' => $data['kode_produk'],
            'satuan' => $data['satuan'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'harga_beli' => $data['harga_beli'] ?? null,
            'harga_jual' => $data['harga_jual'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'gambar' => $data['gambar'] ?? null,
        ]);


        if (isset($data['kategori_ids'])) {
            $produk->kategoriProduks()->sync($data['kategori_ids']);
        }

        return $this->success($produk->load('kategoriProduks'), 'Produk berhasil ditambahkan', 201);
    }

    public function show(Produk $produk)
    {
        return $this->success($produk->load('lokasi', 'kategoriProduks'), 'Detail produk');
    }

    public function update(UpdateProdukRequest $request, Produk $produk)
    {
        $data = $request->validated();

        if ($request->hasFile('gambar')) {
            if ($produk->gambar && \Storage::disk('public')->exists($produk->gambar)) {
                \Storage::disk('public')->delete($produk->gambar);
            }

            $data['gambar'] = $request->file('gambar')->store('produk', 'public');
        }

        $produk->update($data);

        if (isset($data['kategori_ids'])) {
            $produk->kategoriProduks()->sync($data['kategori_ids']);
        }

        return $this->success($produk->load('kategoriProduks'), 'Produk berhasil diupdate');
    }

    public function destroy(Produk $produk)
    {
        $produk->delete();
        return $this->success(null, 'Produk berhasil dihapus');
    }

    public function history(Produk $produk)
    {
        $mutasi = $produk->mutasi()->with('lokasi', 'user')->get();
        return $this->success($mutasi, 'History mutasi untuk produk: ' . $produk->nama_produk);
    }
}
