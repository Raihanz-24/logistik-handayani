<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesDashboardCache;
use App\Services\BarangCodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Barang extends Model
{
    use HasFactory;
    use InvalidatesDashboardCache;

    /** Slug kategori master data yang menandai barang pasar (kompatibilitas data lama). */
    public const PASAR_CATEGORY_SLUG = 'pasar';

    protected $fillable = [
        'nama_barang',
        'kode_barang',
        'satuan',
        'ai_alias',
        'deskripsi',
        'gambar',
        'is_barang_pasar',
    ];

    protected $casts = [
        'is_barang_pasar' => 'boolean',
    ];

    /**
     * Barang "pasar": hanya dicatat pada transaksi belanja, tidak masuk stok gudang,
     * tidak dihitung SAW, dan tidak muncul sebagai stok hampir habis.
     *
     * Toggle `is_barang_pasar` adalah penanda utama; kategori "pasar" lama tetap
     * dihormati agar data sebelumnya tidak berubah perilaku.
     */
    public function isBarangPasar(): bool
    {
        if ((bool) $this->is_barang_pasar) {
            return true;
        }

        $categories = $this->relationLoaded('kategoriBarangs')
            ? $this->kategoriBarangs
            : $this->kategoriBarangs()->get(['kategori_barangs.id', 'nama', 'slug']);

        return $categories->contains(fn ($category): bool => mb_strtolower((string) $category->slug) === self::PASAR_CATEGORY_SLUG
            || mb_strtolower(trim((string) $category->nama)) === 'pasar');
    }

    public function scopeBarangPasar(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('is_barang_pasar', true)
                ->orWhereHas('kategoriBarangs', function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query->where('slug', self::PASAR_CATEGORY_SLUG)
                            ->orWhereRaw('LOWER(TRIM(kategori_barangs.nama)) = ?', ['pasar']);
                    });
                });
        });
    }

    public function scopeBukanBarangPasar(Builder $query): Builder
    {
        // Bukan barang pasar = toggle OFF dan tidak punya kategori "pasar" (kompatibel data lama).
        return $query->where('is_barang_pasar', false)
            ->whereDoesntHave('kategoriBarangs', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('slug', self::PASAR_CATEGORY_SLUG)
                        ->orWhereRaw('LOWER(TRIM(kategori_barangs.nama)) = ?', ['pasar']);
                });
            });
    }

    /**
     * Daftar alias (nama panggilan untuk Asisten AI), sudah dirapikan.
     *
     * @return array<int, string>
     */
    public function aiAliases(): array
    {
        if (blank($this->ai_alias)) {
            return [];
        }

        $aliases = preg_split('/[,;\n]+/u', (string) $this->ai_alias) ?: [];

        return array_values(array_filter(array_map(
            fn (string $alias): string => trim($alias),
            $aliases,
        ), fn (string $alias): bool => $alias !== ''));
    }

    /**
     * Pencarian master barang yang juga mencakup alias AI.
     * Dipakai tool pencarian AI agar ucapan seperti "aqua" menemukan "Galon Aqua".
     */
    public function scopeCariUntukAi(Builder $query, string $keyword): Builder
    {
        $like = '%'.$keyword.'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('nama_barang', 'like', $like)
                ->orWhere('kode_barang', 'like', $like)
                ->orWhere('ai_alias', 'like', $like);
        });
    }

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

    public function catatanItems(): HasMany
    {
        return $this->hasMany(CatatanItem::class);
    }

    public function pengeluaranBelanjaItems(): HasMany
    {
        return $this->hasMany(PengeluaranBelanjaItem::class);
    }

    public function hargaSuppliers(): HasMany
    {
        return $this->hasMany(HargaBarangSupplier::class);
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
