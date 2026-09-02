<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FotoBarangItem extends Model
{
    public const PROCESSING_PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const PROCESSING_COMPLETED = 'completed';

    public const PROCESSING_FAILED = 'failed';

    protected $table = 'foto_barang_items';

    protected $fillable = [
        'foto_barang_session_id',
        'urutan',
        'path',
        'processing_status',
        'processing_attempts',
        'processing_error',
        'processed_at',
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
            'processed_at' => 'datetime',
            'processing_attempts' => 'integer',
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
        $extension = strtolower((string) pathinfo($this->path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        return sprintf(
            '%03d_foto_barang_%s.%s',
            $this->urutan,
            $this->diambil_at?->format('Ymd_His') ?? 'foto',
            $extension,
        );
    }

    public function processingCompleted(): bool
    {
        return $this->processing_status === self::PROCESSING_COMPLETED;
    }

    public function processingFailed(): bool
    {
        return $this->processing_status === self::PROCESSING_FAILED;
    }
}
