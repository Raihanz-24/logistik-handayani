<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Supplier extends Model
{
    protected $fillable = [
        'nama_supplier',
        'kontak_person',
        'telepon',
        'alamat',
        'aktif',
    ];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(function (Supplier $supplier): void {
            if (DB::table('mutasis')->where('supplier_id', $supplier->id)->exists()) {
                throw new RuntimeException('Supplier tidak dapat dihapus karena sudah digunakan dalam riwayat mutasi. Nonaktifkan supplier sebagai gantinya.');
            }
        });
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    public function mutasis(): HasMany
    {
        return $this->hasMany(Mutasi::class);
    }

    public function catatans(): HasMany
    {
        return $this->hasMany(Catatan::class);
    }
}
