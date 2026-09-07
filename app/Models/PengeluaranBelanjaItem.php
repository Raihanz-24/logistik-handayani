<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengeluaranBelanjaItem extends Model
{
    protected $fillable = [
        'pengeluaran_belanja_id',
        'barang_id',
        'kode_barang_snapshot',
        'nama_barang_snapshot',
        'satuan_snapshot',
        'jumlah',
        'harga_satuan',
        'subtotal',
        'keterangan',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:3',
            'harga_satuan' => 'integer',
            'subtotal' => 'integer',
            'urutan' => 'integer',
        ];
    }

    public function pengeluaranBelanja(): BelongsTo
    {
        return $this->belongsTo(PengeluaranBelanja::class);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function namaBarang(): string
    {
        return $this->nama_barang_snapshot ?: ($this->barang?->nama_barang ?? 'Barang terhapus');
    }
}
