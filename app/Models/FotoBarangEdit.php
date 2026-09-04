<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FotoBarangEdit extends Model
{
    protected $fillable = [
        'foto_barang_item_id',
        'user_id',
        'path',
        'waktu_baru',
    ];

    protected function casts(): array
    {
        return [
            'waktu_baru' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (FotoBarangEdit $edit): void {
            if (filled($edit->path)) {
                Storage::disk('local')->delete($edit->path);
            }
        });
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(FotoBarangItem::class, 'foto_barang_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fileName(): string
    {
        return sprintf(
            '%03d_foto_barang_%s.jpg',
            (int) ($this->photo?->urutan ?? 0),
            $this->waktu_baru?->setTimezone('Asia/Jakarta')->format('Ymd_His') ?? 'hasil',
        );
    }
}
