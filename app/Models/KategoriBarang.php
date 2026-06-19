<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KategoriBarang extends Model
{
    use HasFactory;

    protected $table = 'kategori_barangs';

    protected $fillable = ['nama', 'slug'];

    public function barangs(): BelongsToMany
    {
        return $this->belongsToMany(
            Barang::class,
            'barang_kategori_barang',
            'kategori_barang_id',
            'barang_id'
        )->withTimestamps();
    }
}
