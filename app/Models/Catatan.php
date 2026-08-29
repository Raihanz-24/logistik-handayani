<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Catatan extends Model
{
    public const JENIS_BELANJA = 'belanja';

    public const JENIS_BIASA = 'biasa';

    protected $fillable = [
        'user_id',
        'supplier_id',
        'jenis',
        'tanggal',
        'judul',
        'isi',
        'nama_supplier_snapshot',
        'selesai',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'selesai' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Catatan $catatan): void {
            if ($catatan->jenis === self::JENIS_BIASA) {
                $catatan->supplier_id = null;
                $catatan->nama_supplier_snapshot = null;

                return;
            }

            if ($catatan->isDirty('supplier_id')) {
                $catatan->nama_supplier_snapshot = $catatan->supplier_id
                    ? Supplier::query()->whereKey($catatan->supplier_id)->value('nama_supplier')
                    : null;
            }
        });
    }

    /** @return array<string, string> */
    public static function jenisOptions(): array
    {
        return [
            self::JENIS_BELANJA => 'Daftar Belanja',
            self::JENIS_BIASA => 'Catatan Biasa',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin')) {
            return $query;
        }

        return $query->where('user_id', $user->getKey());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatatanItem::class)->orderBy('urutan');
    }
}
