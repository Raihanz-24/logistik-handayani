<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Services\BelanjaTransactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class PengeluaranBelanja extends Model
{
    /** @var array<int, array{0: int, 1: int}> */
    private array $pricePairsBeforeDelete = [];

    /** @var array<int, string> */
    private array $receiptPathsBeforeDelete = [];

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
            $record->pricePairsBeforeDelete = $record->items()
                ->pluck('barang_id')
                ->map(fn (mixed $barangId): array => [(int) $record->supplier_id, (int) $barangId])
                ->all();
            $record->receiptPathsBeforeDelete = $record->notas()->pluck('path')->all();

            if (filled($record->foto_nota)) {
                $record->receiptPathsBeforeDelete[] = $record->foto_nota;
            }
        });

        static::deleted(function (PengeluaranBelanja $record): void {
            Storage::disk('public')->delete($record->receiptPathsBeforeDelete);
            $record->audit('delete', 'Menghapus pengeluaran belanja');
            app(BelanjaTransactionService::class)
                ->refreshPricePairs($record->pricePairsBeforeDelete);
        });
    }

    public function kalkulatorBelanja(): BelongsTo
    {
        return $this->belongsTo(KalkulatorBelanja::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PengeluaranBelanjaItem::class)->orderBy('urutan');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(PengeluaranBelanjaNota::class)->orderBy('urutan');
    }

    public function fotoBarangSessions(): BelongsToMany
    {
        return $this->belongsToMany(
            FotoBarangSession::class,
            'foto_barang_session_pengeluaran_belanja',
        )->withTimestamps();
    }

    public function jumlahNota(): int
    {
        $newReceipts = $this->relationLoaded('notas')
            ? $this->notas->count()
            : $this->notas()->count();

        return $newReceipts + (filled($this->foto_nota) ? 1 : 0);
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
