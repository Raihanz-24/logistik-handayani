<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PengeluaranBelanjaNota extends Model
{
    protected $fillable = [
        'pengeluaran_belanja_id',
        'path',
        'urutan',
    ];

    protected function casts(): array
    {
        return ['urutan' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(function (PengeluaranBelanjaNota $nota): void {
            if (filled($nota->path)) {
                Storage::disk('public')->delete($nota->path);
            }
        });
    }

    public function pengeluaranBelanja(): BelongsTo
    {
        return $this->belongsTo(PengeluaranBelanja::class);
    }
}
