<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPendingAction extends Model
{
    public const TYPE_MUTASI_DRAFT = 'mutasi_draft';

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
}
