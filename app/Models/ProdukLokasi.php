<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProdukLokasi extends Pivot
{
    protected $table = 'produk_lokasi';

    protected $fillable = [
        'produk_id',
        'lokasi_id',
        'stok',
    ];

    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'produk_id');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    public function mutasiWidget()
    {
        return $this->hasMany(Mutasi::class, 'produk_id', 'produk_id')
            ->whereColumn('lokasi_id', 'produk_lokasi.lokasi_id');
    }
}
