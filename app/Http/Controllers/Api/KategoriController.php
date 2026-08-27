<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreKategoriRequest;
use App\Http\Requests\UpdateKategoriRequest;
use App\Models\KategoriBarang;
use Illuminate\Support\Facades\Gate;

class KategoriController extends BaseApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('viewAny', KategoriBarang::class);

        $categories = KategoriBarang::all();

        return $this->success($categories, 'Daftar kategori');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreKategoriRequest $request)
    {
        Gate::authorize('create', KategoriBarang::class);

        $kategori = KategoriBarang::create($request->validated());

        return $this->success($kategori, 'Kategori berhasil ditambahkan', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(KategoriBarang $kategori)
    {
        Gate::authorize('view', $kategori);

        return $this->success($kategori, 'Detail kategori');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateKategoriRequest $request, KategoriBarang $kategori)
    {
        Gate::authorize('update', $kategori);

        $kategori->update($request->validated());

        return $this->success($kategori, 'Kategori berhasil diperbarui');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(KategoriBarang $kategori)
    {
        Gate::authorize('delete', $kategori);

        $kategori->delete();

        return $this->success(null, 'Kategori berhasil dihapus');
    }
}
