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
        'posisi_rak_id',
        'stok',
        'stok_baik',
        'stok_rusak',
        'stok_hilang',
    ];

    protected function casts(): array
    {
        return [
            'stok' => 'integer',
            'stok_baik' => 'integer',
            'stok_rusak' => 'integer',
            'stok_hilang' => 'integer',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    public function posisiRak(): BelongsTo
    {
        return $this->belongsTo(PosisiRak::class, 'posisi_rak_id');
    }

    public function posisiRakTampil(): BelongsTo
    {
        return $this->belongsTo(PosisiRak::class, 'posisi_rak_tampil_id');
    }

    public static function conditionColumn(string $condition): string
    {
        return match ($condition) {
            'baik' => 'stok_baik',
            'rusak' => 'stok_rusak',
            'hilang' => 'stok_hilang',
            default => throw new \InvalidArgumentException('Kondisi barang tidak valid.'),
        };
    }

    public function mutasiWidget()
    {
        return $this->hasMany(Mutasi::class, 'barang_id', 'barang_id')
            ->whereColumn('lokasi_id', 'barang_lokasi.lokasi_id');
    }
}
