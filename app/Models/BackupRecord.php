<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRecord extends Model
{
    public const TYPE_MANUAL = 'manual';

    public const TYPE_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'created_by',
        'type',
        'status',
        'database_path',
        'files_path',
        'database_size',
        'files_size',
        'error_message',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'database_size' => 'integer',
            'files_size' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalSize(): int
    {
        return (int) $this->database_size + (int) $this->files_size;
    }
}
