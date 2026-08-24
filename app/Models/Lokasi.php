<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lokasi extends Model
{
    use HasFactory;

    public const JENIS_GUDANG = 'gudang';

    public const JENIS_PEMAKAIAN = 'lokasi_pemakaian';

    protected $fillable = [
        'nama_lokasi',
        'kode_lokasi',
        'jenis_lokasi',
        'menggunakan_rak',
        'konfigurasi_rak',
        'alamat',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'menggunakan_rak' => 'boolean',
            'konfigurasi_rak' => 'array',
        ];
    }

    public static function jenisOptions(): array
    {
        return [
            self::JENIS_GUDANG => 'Gudang',
            self::JENIS_PEMAKAIAN => 'Lokasi Pemakaian',
        ];
    }

    public function scopeGudang(Builder $query): Builder
    {
        return $query->where('jenis_lokasi', self::JENIS_GUDANG);
    }

    public function scopeLokasiPemakaian(Builder $query): Builder
    {
        return $query->where('jenis_lokasi', self::JENIS_PEMAKAIAN);
    }

    public function isGudang(): bool
    {
        return $this->jenis_lokasi === self::JENIS_GUDANG;
    }

    public function isLokasiPemakaian(): bool
    {
        return $this->jenis_lokasi === self::JENIS_PEMAKAIAN;
    }

    public function barang(): BelongsToMany
    {
        return $this->belongsToMany(Barang::class, 'barang_lokasi')
            ->withPivot(['stok', 'stok_baik', 'stok_rusak', 'stok_hilang', 'posisi_rak_id'])
            ->using(BarangLokasi::class);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(Mutasi::class);
    }

    public function mutasiTujuan(): HasMany
    {
        return $this->hasMany(Mutasi::class, 'lokasi_tujuan_id');
    }

    public function mutasiRaks(): HasMany
    {
        return $this->hasMany(MutasiRak::class);
    }

    public function raks(): HasMany
    {
        return $this->hasMany(RakGudang::class)->orderBy('nomor_rak');
    }

    public function posisiRaks(): HasMany
    {
        return $this->hasMany(PosisiRak::class)->orderBy('kode');
    }
}
