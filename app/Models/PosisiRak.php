<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosisiRak extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (PosisiRak $position): void {
            if (DB::table('barang_lokasi')->where('posisi_rak_id', $position->id)->where('stok', '>', 0)->exists()) {
                throw new RuntimeException("Posisi {$position->kode} tidak dapat dihapus karena masih menyimpan stok.");
            }
        });
    }

    protected $fillable = [
        'rak_gudang_id',
        'lokasi_id',
        'nomor_tingkat',
        'kode',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'nomor_tingkat' => 'integer',
        ];
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    public function rakGudang(): BelongsTo
    {
        return $this->belongsTo(RakGudang::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function barangLokasi(): HasMany
    {
        return $this->hasMany(BarangLokasi::class, 'posisi_rak_id');
    }

    public function mutasiRakAsal(): HasMany
    {
        return $this->hasMany(MutasiRak::class, 'posisi_rak_asal_id');
    }

    public function mutasiRakTujuan(): HasMany
    {
        return $this->hasMany(MutasiRak::class, 'posisi_rak_tujuan_id');
    }
}
