<?php

namespace App\Models;

use App\Services\BarangCodeGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Barang extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_barang',
        'kode_barang',
        'satuan',
        'deskripsi',
        'gambar',
    ];

    protected static function booted(): void
    {
        static::creating(function (Barang $barang): void {
            $barang->kode_barang = app(BarangCodeGenerator::class)->next();
        });

        static::updated(function (Barang $barang): void {
            if (! $barang->wasChanged('gambar')) {
                return;
            }

            $oldImage = $barang->getRawOriginal('gambar');

            if (filled($oldImage) && $oldImage !== $barang->gambar) {
                Storage::disk('public')->delete($oldImage);
            }
        });

        static::deleted(function (Barang $barang): void {
            if (filled($barang->gambar)) {
                Storage::disk('public')->delete($barang->gambar);
            }
        });
    }

    public function lokasi(): BelongsToMany
    {
        return $this->belongsToMany(Lokasi::class, 'barang_lokasi')
            ->where('lokasis.jenis_lokasi', Lokasi::JENIS_GUDANG)
            ->withPivot(['stok', 'stok_baik', 'stok_rusak', 'stok_hilang', 'posisi_rak_id'])
            ->using(BarangLokasi::class);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(Mutasi::class);
    }

    public function mutasiRaks(): HasMany
    {
        return $this->hasMany(MutasiRak::class);
    }

    public function kategoriBarangs(): BelongsToMany
    {
        return $this->belongsToMany(
            KategoriBarang::class,
            'barang_kategori_barang',
            'barang_id',
            'kategori_barang_id'
        )->withTimestamps();
    }
}
