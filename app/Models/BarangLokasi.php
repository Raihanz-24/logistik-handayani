<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BarangLokasi extends Pivot
{
    protected $table = 'barang_lokasi';

    protected $fillable = [
        'barang_id',
        'lokasi_id',
        'stok',
    ];

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    public function mutasiWidget()
    {
        return $this->hasMany(Mutasi::class, 'barang_id', 'barang_id')
            ->whereColumn('lokasi_id', 'barang_lokasi.lokasi_id');
    }
}
