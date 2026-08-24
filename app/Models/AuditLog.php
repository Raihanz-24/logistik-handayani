<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const MAX_RECORDS = 1000;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'event',
        'description',
        'method',
        'route_name',
        'path',
        'ip_address',
        'user_agent',
        'status_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'status_code' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (): mixed => static::pruneOldest());
    }

    public static function pruneOldest(): int
    {
        $excess = static::query()->count() - self::MAX_RECORDS;

        if ($excess <= 0) {
            return 0;
        }

        $oldestIds = static::query()
            ->oldest('id')
            ->limit($excess)
            ->pluck('id');

        return static::query()->whereKey($oldestIds)->delete();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
