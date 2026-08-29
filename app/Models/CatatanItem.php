<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatatanItem extends Model
{
    protected $fillable = [
        'catatan_id',
        'barang_id',
        'nama_barang_snapshot',
        'satuan_snapshot',
        'jumlah',
        'keterangan',
        'sudah_dibeli',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'sudah_dibeli' => 'boolean',
            'urutan' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CatatanItem $item): void {
            if (! $item->barang_id || (! $item->isDirty('barang_id') && filled($item->nama_barang_snapshot))) {
                return;
            }

            $barang = Barang::query()->find($item->barang_id);

            if ($barang) {
                $item->nama_barang_snapshot = $barang->nama_barang;
                $item->satuan_snapshot = $barang->satuan;
            }
        });
    }

    public function catatan(): BelongsTo
    {
        return $this->belongsTo(Catatan::class);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function namaBarang(): string
    {
        return $this->barang?->nama_barang ?? $this->nama_barang_snapshot;
    }
}
