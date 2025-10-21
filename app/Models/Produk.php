<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produk extends Model
{
    use HasFactory;
    protected $fillable = [
        'nama_produk',
        'kode_produk',
        'satuan',
        'deskripsi',
        'harga_beli',
        'harga_jual',
        'barcode',
        'gambar',
    ];

    public function lokasi(): BelongsToMany
    {
        return $this->belongsToMany(Lokasi::class, 'produk_lokasi')
            ->withPivot('stok')
            ->using(ProdukLokasi::class);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(Mutasi::class);
    }

    public function kategoriProduks(): BelongsToMany
    {
        return $this->belongsToMany(KategoriProduk::class, 'kategori_produk_produk');
    }

}

