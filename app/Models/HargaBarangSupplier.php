<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HargaBarangSupplier extends Model
{
    protected $fillable = [
        'supplier_id',
        'barang_id',
        'harga_terakhir',
        'tanggal_harga_terakhir',
    ];

    protected function casts(): array
    {
        return [
            'harga_terakhir' => 'integer',
            'tanggal_harga_terakhir' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }
}
