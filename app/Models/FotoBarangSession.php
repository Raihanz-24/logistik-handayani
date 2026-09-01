<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class FotoBarangSession extends Model
{
    public const STATUS_AKTIF = 'aktif';

    public const STATUS_SELESAI = 'selesai';

    protected $table = 'foto_barang_sessions';

    protected $fillable = [
        'uuid',
        'user_id',
        'judul',
        'nama_lokasi',
        'alamat',
        'status',
        'dimulai_at',
        'selesai_at',
    ];

    protected function casts(): array
    {
        return [
            'dimulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (FotoBarangSession $session): void {
            Storage::disk('local')->deleteDirectory($session->storageDirectory());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin')) {
            return $query;
        }

        return $query->where('user_id', $user->getKey());
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }

    public function storageDirectory(): string
    {
        return 'foto-barang/'.$this->uuid;
    }

    public function code(): string
    {
        return 'SFM-'.$this->dimulai_at?->format('Ymd').'-'.str_pad((string) $this->getKey(), 4, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FotoBarangItem::class)->orderBy('urutan');
    }
}
