<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KategoriProduk extends Model
{
    use HasFactory;
    protected $fillable = ['nama', 'slug'];

    public function produks()
    {
        return $this->belongsToMany(Produk::class, 'kategori_produk_produk');
    }
}
