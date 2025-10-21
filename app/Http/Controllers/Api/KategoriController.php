<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKategoriRequest;
use App\Http\Requests\UpdateKategoriRequest;
use App\Models\KategoriProduk;
use Illuminate\Http\Request;

class KategoriController extends BaseApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = KategoriProduk::all();
        return $this->success($categories, 'Daftar kategori');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreKategoriRequest $request)
    {
        $kategori = KategoriProduk::create($request->validated());
        return $this->success($kategori, 'Kategori berhasil ditambahkan', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(KategoriProduk $kategori)
    {
        return $this->success($kategori, 'Detail kategori');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateKategoriRequest $request, KategoriProduk $kategori)
    {
        $kategori->update($request->validated());
        return $this->success($kategori, 'Kategori berhasil diperbarui');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(KategoriProduk $kategori)
    {
        $kategori->delete();
        return $this->success(null, 'Kategori berhasil dihapus');
    }
}
