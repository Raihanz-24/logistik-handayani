<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FotoBarangItem extends Model
{
    protected $table = 'foto_barang_items';

    protected $fillable = [
        'foto_barang_session_id',
        'urutan',
        'path',
        'latitude',
        'longitude',
        'akurasi_meter',
        'diambil_at',
        'ukuran_asli',
        'ukuran_hasil',
        'lebar',
        'tinggi',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'akurasi_meter' => 'integer',
            'diambil_at' => 'datetime',
            'ukuran_asli' => 'integer',
            'ukuran_hasil' => 'integer',
            'lebar' => 'integer',
            'tinggi' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(FotoBarangSession::class, 'foto_barang_session_id');
    }

    public function fileName(): string
    {
        return sprintf(
            '%03d_foto_barang_%s.jpg',
            $this->urutan,
            $this->diambil_at?->format('Ymd_His') ?? 'foto',
        );
    }
}
