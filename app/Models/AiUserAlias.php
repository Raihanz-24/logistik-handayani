<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias pribadi per pengguna: frasa yang diucapkan user -> barang yang dipilih.
 *
 * Dipakai Asisten AI agar makin mengerti pola ucapan pengguna (mis. user selalu
 * menyebut "wortel" untuk barang tertentu). Bukan data global; berlaku per user.
 */
class AiUserAlias extends Model
{
    protected $fillable = [
        'user_id',
        'barang_id',
        'keyword',
        'barang_nama',
        'hits',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
        ];
    }

    /** Normalisasi frasa menjadi kunci pencarian. */
    public static function normalizeKeyword(string $keyword): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }
}
