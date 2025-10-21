<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lokasi extends Model
{
    use HasFactory;
    protected $fillable = [
        'nama_lokasi',
        'kode_lokasi',
        'alamat',
        'keterangan'
    ];

    public function produk(): BelongsToMany
    {
        return $this->belongsToMany(Produk::class, 'produk_lokasi')
            ->withPivot('stok')
            ->using(ProdukLokasi::class);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(Mutasi::class);
    }

}
