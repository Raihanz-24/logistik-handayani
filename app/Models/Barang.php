<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barang extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_barang',
        'kode_barang',
        'satuan',
        'deskripsi',
    ];

    public function lokasi(): BelongsToMany
    {
        return $this->belongsToMany(Lokasi::class, 'barang_lokasi')
            ->where('lokasis.jenis_lokasi', Lokasi::JENIS_GUDANG)
            ->withPivot('stok')
            ->using(BarangLokasi::class);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(Mutasi::class);
    }

    public function kategoriBarangs(): BelongsToMany
    {
        return $this->belongsToMany(
            KategoriBarang::class,
            'barang_kategori_barang',
            'barang_id',
            'kategori_barang_id'
        )->withTimestamps();
    }
}
