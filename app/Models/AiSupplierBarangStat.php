<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregat afinitas supplier <-> barang (turunan dari transaksi belanja).
 *
 * @property int $supplier_id
 * @property int $barang_id
 * @property int $frekuensi
 * @property string $total_jumlah
 * @property string|null $satuan_terakhir
 * @property int|null $harga_terakhir
 * @property \Illuminate\Support\Carbon|null $tanggal_terakhir
 */
class AiSupplierBarangStat extends Model
{
    protected $fillable = [
        'supplier_id',
        'barang_id',
        'frekuensi',
        'total_jumlah',
        'satuan_terakhir',
        'harga_terakhir',
        'tanggal_terakhir',
    ];

    protected function casts(): array
    {
        return [
            'frekuensi' => 'integer',
            'total_jumlah' => 'decimal:3',
            'harga_terakhir' => 'integer',
            'tanggal_terakhir' => 'date',
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
