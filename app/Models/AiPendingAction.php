<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPendingAction extends Model
{
    public const TYPE_MUTASI_DRAFT = 'mutasi_draft';

    /** Beberapa draft mutasi sekaligus dalam satu konfirmasi. */
    public const TYPE_MUTASI_BATCH = 'mutasi_batch';

    public const TYPE_PENGELUARAN_BELANJA = 'pengeluaran_belanja';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Digantikan oleh draft yang lebih baru pada percakapan yang sama. */
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ai_conversation_id',
        'ai_message_id',
        'user_id',
        'type',
        'payload',
        'status',
        'summary',
        'result_type',
        'result_id',
        'error_message',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Jumlah item pada draft batch mutasi (0 bila bukan batch). */
    public function itemCount(): int
    {
        if ($this->type !== self::TYPE_MUTASI_BATCH) {
            return 0;
        }

        $items = $this->payload['items'] ?? [];

        return is_array($items) ? count($items) : 0;
    }

    /** Judul kartu konfirmasi sesuai jenis aksi. */
    public function confirmTitle(): string
    {
        return match ($this->type) {
            self::TYPE_MUTASI_DRAFT => 'Konfirmasi draft mutasi',
            self::TYPE_MUTASI_BATCH => 'Konfirmasi '.$this->itemCount().' draft mutasi',
            default => 'Konfirmasi transaksi belanja',
        };
    }

    /** Label tombol konfirmasi sesuai jenis aksi. */
    public function confirmButtonLabel(): string
    {
        return match ($this->type) {
            self::TYPE_MUTASI_DRAFT => 'Buat Draft',
            self::TYPE_MUTASI_BATCH => 'Buat Semua Draft',
            default => 'Catat Transaksi',
        };
    }
}
