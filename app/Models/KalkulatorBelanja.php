<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Services\BelanjaTransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KalkulatorBelanja extends Model
{
    protected $fillable = [
        'user_id',
        'tanggal',
        'judul',
        'uang_awal',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'uang_awal' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (KalkulatorBelanja $record) => $record->audit('create', 'Membuat sesi kalkulator belanja'));
        static::updated(fn (KalkulatorBelanja $record) => $record->audit('update', 'Memperbarui sesi kalkulator belanja'));
        static::updated(function (KalkulatorBelanja $record): void {
            if (! $record->wasChanged('tanggal')) {
                return;
            }

            $pairs = $record->pengeluaran()
                ->with('items:id,pengeluaran_belanja_id,barang_id')
                ->get()
                ->flatMap(fn (PengeluaranBelanja $expense) => $expense->items->map(
                    fn ($item): array => [(int) $expense->supplier_id, (int) $item->barang_id],
                ))
                ->all();

            app(BelanjaTransactionService::class)->refreshPricePairs($pairs);
        });

        static::deleting(function (KalkulatorBelanja $record): void {
            $record->pengeluaran()->get()->each->delete();
        });

        static::deleted(fn (KalkulatorBelanja $record) => $record->audit('delete', 'Menghapus sesi kalkulator belanja'));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->can('view_any_kalkulator_belanja')) {
            return $query;
        }

        return $query->where('user_id', $user->getKey());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pengeluaran(): HasMany
    {
        return $this->hasMany(PengeluaranBelanja::class)->orderBy('urutan');
    }

    public function getTotalPengeluaranAttribute(): int
    {
        if (array_key_exists('pengeluaran_sum_nominal', $this->attributes)) {
            return (int) ($this->attributes['pengeluaran_sum_nominal'] ?? 0);
        }

        if ($this->relationLoaded('pengeluaran')) {
            return (int) $this->pengeluaran->sum('nominal');
        }

        return (int) $this->pengeluaran()->sum('nominal');
    }

    public function getSisaUangAttribute(): int
    {
        return (int) $this->uang_awal - $this->total_pengeluaran;
    }

    public function hasInitialMoney(): bool
    {
        return (int) $this->uang_awal > 0;
    }

    private function audit(string $action, string $description): void
    {
        app(AuditLogger::class)->activity(
            "kalkulator_belanja_{$action}",
            "{$description}: {$this->judul}",
            metadata: ['kalkulator_belanja_id' => $this->getKey()],
        );
    }
}
