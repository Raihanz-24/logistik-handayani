<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu contoh pelatihan (distilasi) dari percakapan Asisten AI.
 *
 * @property int $ai_conversation_id
 * @property int $user_id
 * @property string|null $provider
 * @property string $input
 * @property array<int, array<string, mixed>>|null $actions
 * @property string|null $output
 * @property bool $exported
 */
class AiTrainingExample extends Model
{
    protected $fillable = [
        'ai_conversation_id',
        'user_id',
        'provider',
        'input',
        'actions',
        'output',
        'exported',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'exported' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
