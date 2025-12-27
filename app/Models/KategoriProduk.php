<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KategoriProduk extends Model
{
    use HasFactory;

    protected $table = 'kategori_produks';

    protected $fillable = ['nama', 'slug'];

    public function produks(): BelongsToMany
    {
        return $this->belongsToMany(
            Produk::class,
            'kategori_produk_produk',
            'kategori_produk_id',
            'produk_id'
        )->withTimestamps();
    }
}
