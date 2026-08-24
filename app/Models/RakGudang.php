<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RakGudang extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (RakGudang $rack): void {
            $hasStock = DB::table('barang_lokasi')
                ->join('posisi_raks', 'posisi_raks.id', '=', 'barang_lokasi.posisi_rak_id')
                ->where('posisi_raks.rak_gudang_id', $rack->id)
                ->where('barang_lokasi.stok', '>', 0)
                ->exists();

            if ($hasStock) {
                throw new RuntimeException('Rak tidak dapat dihapus karena masih menyimpan stok.');
            }
        });
    }

    protected $fillable = [
        'lokasi_id',
        'nomor_rak',
        'jumlah_tingkat',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'nomor_rak' => 'integer',
            'jumlah_tingkat' => 'integer',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function posisi(): HasMany
    {
        return $this->hasMany(PosisiRak::class)->orderBy('nomor_tingkat');
    }
}
