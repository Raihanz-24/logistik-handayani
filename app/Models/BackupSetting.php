<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class BackupSetting extends Model
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $fillable = [
        'enabled',
        'frequency',
        'backup_time',
        'weekly_day',
        'monthly_day',
        'include_files',
        'keep_count',
        'last_scheduled_key',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'include_files' => 'boolean',
            'weekly_day' => 'integer',
            'monthly_day' => 'integer',
            'keep_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function isDue(CarbonImmutable $now): bool
    {
        if (! $this->enabled || ! in_array($this->frequency, self::FREQUENCIES, true)) {
            return false;
        }

        if ($now->format('H:i') !== substr((string) $this->backup_time, 0, 5)) {
            return false;
        }

        return match ($this->frequency) {
            'daily' => true,
            'weekly' => $now->dayOfWeek === $this->weekly_day,
            'monthly' => $now->day === min($this->monthly_day, $now->daysInMonth),
            default => false,
        };
    }

    public function scheduleKey(CarbonImmutable $now): string
    {
        return match ($this->frequency) {
            'weekly' => 'weekly-'.$now->format('o-W'),
            'monthly' => 'monthly-'.$now->format('Y-m'),
            default => 'daily-'.$now->toDateString(),
        };
    }
}
