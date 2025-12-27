<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mutasi extends Model
{
    use HasFactory;

    protected $fillable = [
        'tanggal',
        'jenis_mutasi',
        'jumlah',
        'keterangan',
        'no_ref',
        'status',

        'user_id',
        'produk_id',

        // gudang asal (keluar) / gudang tujuan (masuk)
        'lokasi_id',

        // tujuan mutasi keluar
        'lokasi_tujuan_id',

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
    ];

    protected $casts = [
        'tanggal' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class);
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
