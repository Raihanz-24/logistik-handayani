<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PengeluaranBelanja extends Model
{
    protected $fillable = [
        'kalkulator_belanja_id',
        'supplier_id',
        'nama_supplier_snapshot',
        'nominal',
        'foto_nota',
        'keterangan',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'urutan' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PengeluaranBelanja $record): void {
            if (! $record->supplier_id || (! $record->isDirty('supplier_id') && filled($record->nama_supplier_snapshot))) {
                return;
            }

            $record->nama_supplier_snapshot = Supplier::query()
                ->whereKey($record->supplier_id)
                ->value('nama_supplier') ?? 'Supplier tidak ditemukan';
        });

        static::created(fn (PengeluaranBelanja $record) => $record->audit('create', 'Menambahkan pengeluaran belanja'));
        static::updated(fn (PengeluaranBelanja $record) => $record->audit('update', 'Memperbarui pengeluaran belanja'));

        static::deleting(function (PengeluaranBelanja $record): void {
            if (filled($record->foto_nota)) {
                Storage::disk('public')->delete($record->foto_nota);
            }
        });

        static::deleted(fn (PengeluaranBelanja $record) => $record->audit('delete', 'Menghapus pengeluaran belanja'));
    }

    public function kalkulatorBelanja(): BelongsTo
    {
        return $this->belongsTo(KalkulatorBelanja::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function namaSupplier(): string
    {
        return $this->supplier?->nama_supplier ?? $this->nama_supplier_snapshot;
    }

    private function audit(string $action, string $description): void
    {
        app(AuditLogger::class)->activity(
            "pengeluaran_belanja_{$action}",
            "{$description}: {$this->nama_supplier_snapshot}",
            metadata: [
                'pengeluaran_belanja_id' => $this->getKey(),
                'kalkulator_belanja_id' => $this->kalkulator_belanja_id,
            ],
        );
    }
}
