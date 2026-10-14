<?php

namespace App\Models;

use App\Services\Ai\AiTextFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tool_name',
        'tool_call_id',
        'tool_payload',
    ];

    protected function casts(): array
    {
        return [
            'tool_payload' => 'array',
        ];
    }

    /**
     * Konten siap tampil: balasan asisten lama mungkin masih menyimpan penanda
     * Markdown ('#', '*'), jadi dirapikan saat diakses.
     */
    public function getDisplayContentAttribute(): string
    {
        $content = (string) ($this->content ?? '');

        if ($this->role !== self::ROLE_ASSISTANT) {
            return $content;
        }

        return AiTextFormatter::toPlain($content);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
