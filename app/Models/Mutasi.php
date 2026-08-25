<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mutasi extends Model
{
    use HasFactory;

    public const KONDISI_BAIK = 'baik';

    public const KONDISI_RUSAK = 'rusak';

    public const KONDISI_HILANG = 'hilang';

    protected $fillable = [
        'tanggal',
        'jenis_mutasi',
        'kondisi_asal',
        'kondisi_tujuan',
        'jumlah',
        'keterangan',
        'no_ref',
        'status',

        'user_id',
        'barang_id',
        'supplier_id',

        // gudang asal (keluar) / gudang tujuan (masuk)
        'lokasi_id',

        // tujuan mutasi keluar
        'lokasi_tujuan_id',
        'posisi_rak_asal_id',
        'posisi_rak_tujuan_id',

        // audit
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',

        // snapshot stok gudang yang terdampak (lokasi_id)
        'stok_awal',
        'stok_akhir',
        'stok_kondisi_asal_awal',
        'stok_kondisi_asal_akhir',
        'stok_kondisi_tujuan_awal',
        'stok_kondisi_tujuan_akhir',
    ];

    public static function kondisiOptions(): array
    {
        return [
            self::KONDISI_BAIK => 'Baik',
            self::KONDISI_RUSAK => 'Rusak',
            self::KONDISI_HILANG => 'Hilang',
        ];
    }

    public static function jenisOptions(): array
    {
        return [
            'masuk' => 'Masuk',
            'keluar' => 'Keluar / Transfer',
            'perubahan_kondisi' => 'Perubahan Kondisi',
        ];
    }

    protected $casts = [
        'tanggal' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    // ✅ tujuan mutasi keluar
    public function lokasiTujuan(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_tujuan_id');
    }

    public function posisiRakAsal(): BelongsTo
    {
        return $this->belongsTo(PosisiRak::class, 'posisi_rak_asal_id');
    }

    public function posisiRakTujuan(): BelongsTo
    {
        return $this->belongsTo(PosisiRak::class, 'posisi_rak_tujuan_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
