<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MutasiRak extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tanggal',
        'barang_id',
        'lokasi_id',
        'posisi_rak_asal_id',
        'posisi_rak_tujuan_id',
        'stok',
        'stok_baik',
        'stok_rusak',
        'stok_hilang',
        'status',
        'no_ref',
        'keterangan',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'stok' => 'integer',
            'stok_baik' => 'integer',
            'stok_rusak' => 'integer',
            'stok_hilang' => 'integer',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_CANCELLED => 'Dibatalkan',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
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
