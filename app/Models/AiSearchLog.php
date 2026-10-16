<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan satu pencarian barang oleh Asisten AI (untuk penilaian kualitas
 * pengenalan barang). Turunan murni; aman dipangkas/dihapus.
 *
 * @property int $user_id
 * @property string|null $kata_kunci
 * @property int $jumlah_kandidat
 * @property int|null $pilihan_terbaik_barang_id
 * @property int $skor_teratas
 * @property bool $ambigu
 */
class AiSearchLog extends Model
{
    protected $fillable = [
        'user_id',
        'kata_kunci',
        'jumlah_kandidat',
        'pilihan_terbaik_barang_id',
        'skor_teratas',
        'ambigu',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_kandidat' => 'integer',
            'pilihan_terbaik_barang_id' => 'integer',
            'skor_teratas' => 'integer',
            'ambigu' => 'boolean',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'pilihan_terbaik_barang_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
