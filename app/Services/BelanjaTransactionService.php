<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\FotoBarangItemBelanjaLink;
use App\Models\HargaBarangSupplier;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\PengeluaranBelanjaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BelanjaTransactionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(
        KalkulatorBelanja $session,
        ?PengeluaranBelanja $expense,
        array $data,
    ): PengeluaranBelanja {
        if ($expense && (int) $expense->kalkulator_belanja_id !== (int) $session->getKey()) {
            abort(404);
        }

        $validated = Validator::make($data, [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.barang_id' => ['required', 'integer', 'distinct', 'exists:barangs,id'],
            'items.*.jumlah' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'items.*.harga_satuan' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'items.*.keterangan' => ['nullable', 'string', 'max:500'],
            'nota_paths' => ['nullable', 'array', 'max:20'],
            'nota_paths.*' => ['required', 'string', 'distinct', 'max:500'],
        ], [
            'items.required' => 'Minimal satu barang wajib diisi.',
            'items.*.barang_id.distinct' => 'Barang yang sama tidak boleh dicatat dua kali dalam satu transaksi toko.',
            'items.*.jumlah.gt' => 'Jumlah barang harus lebih dari nol.',
            'nota_paths.max' => 'Maksimal 20 foto nota untuk satu transaksi toko.',
        ])->validate();

        $paths = $this->validatedReceiptPaths($validated['nota_paths'] ?? [], $expense);
        $barangs = Barang::query()
            ->whereKey(collect($validated['items'])->pluck('barang_id')->all())
            ->get()
            ->keyBy('id');
        $itemRows = [];
        $total = 0;

        foreach (array_values($validated['items']) as $index => $item) {
            $barang = $barangs->get((int) $item['barang_id']);

            if (! $barang) {
                throw ValidationException::withMessages([
                    "items.{$index}.barang_id" => 'Barang tidak lagi tersedia di master data.',
                ]);
            }

            $quantity = $this->normalizeQuantity($item['jumlah']);
            $unitPrice = (int) $item['harga_satuan'];
            $subtotal = $this->subtotal($quantity, $unitPrice);
            $total += $subtotal;

            if ($total > 999_999_999_999) {
                throw ValidationException::withMessages([
                    'items' => 'Total transaksi melebihi batas Rp999.999.999.999.',
                ]);
            }

            $itemRows[] = [
                'barang_id' => (int) $barang->getKey(),
                'kode_barang_snapshot' => (string) $barang->kode_barang,
                'nama_barang_snapshot' => (string) $barang->nama_barang,
                'satuan_snapshot' => (string) $barang->satuan,
                'jumlah' => $quantity,
                'harga_satuan' => $unitPrice,
                'subtotal' => $subtotal,
                'keterangan' => filled($item['keterangan'] ?? null)
                    ? trim((string) $item['keterangan'])
                    : null,
                'urutan' => $index,
            ];
        }

        $oldPaths = $expense?->notas()->pluck('path')->all() ?? [];
        $photoIdsByBarang = [];

        if ($expense && Schema::hasTable('foto_barang_item_belanja_links')) {
            $photoIdsByBarang = $expense->items()
                ->with('photoLinks:foto_barang_item_id,pengeluaran_belanja_item_id')
                ->get(['id', 'barang_id'])
                ->mapWithKeys(fn (PengeluaranBelanjaItem $item): array => [
                    (int) $item->barang_id => $item->photoLinks
                        ->pluck('foto_barang_item_id')
                        ->map(fn (mixed $photoId): int => (int) $photoId)
                        ->all(),
                ])
                ->all();
        }
        $oldPairs = $expense?->items()->get(['barang_id'])->map(
            fn (PengeluaranBelanjaItem $item): array => [
                (int) $expense->supplier_id,
                (int) $item->barang_id,
            ],
        )->all() ?? [];

        try {
            $saved = DB::transaction(function () use (
                $session,
                $expense,
                $validated,
                $itemRows,
                $total,
                $paths,
                $photoIdsByBarang,
            ): PengeluaranBelanja {
                $record = $expense ?? new PengeluaranBelanja;
                $record->fill([
                    'supplier_id' => (int) $validated['supplier_id'],
                    'nominal' => $total,
                    'keterangan' => filled($validated['keterangan'] ?? null)
                        ? trim((string) $validated['keterangan'])
                        : null,
                ]);

                if (! $record->exists) {
                    $record->urutan = ((int) $session->pengeluaran()->max('urutan')) + 1;
                    $session->pengeluaran()->save($record);
                } else {
                    $record->save();
                }

                $record->items()->delete();
                $createdItems = $record->items()->createMany($itemRows);

                if ($photoIdsByBarang !== [] && Schema::hasTable('foto_barang_item_belanja_links')) {
                    $now = now();
                    $restoredLinks = $createdItems->flatMap(function (PengeluaranBelanjaItem $item) use ($photoIdsByBarang, $now): array {
                        return collect($photoIdsByBarang[(int) $item->barang_id] ?? [])
                            ->map(fn (int $photoId): array => [
                                'foto_barang_item_id' => $photoId,
                                'pengeluaran_belanja_item_id' => (int) $item->getKey(),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ])
                            ->all();
                    })->all();

                    if ($restoredLinks !== []) {
                        FotoBarangItemBelanjaLink::query()->insert($restoredLinks);
                    }
                }

                $record->notas()->whereNotIn('path', $paths)->delete();

                foreach ($paths as $index => $path) {
                    $record->notas()->updateOrCreate(
                        ['path' => $path],
                        ['urutan' => $index],
                    );
                }

                return $record;
            });
        } catch (\Throwable $exception) {
            collect($paths)
                ->diff($oldPaths)
                ->each(fn (string $path): bool => Storage::disk('public')->delete($path));

            throw $exception;
        }

        collect($oldPaths)
            ->diff($paths)
            ->each(fn (string $path): bool => Storage::disk('public')->delete($path));

        $newPairs = collect($itemRows)->map(fn (array $row): array => [
            (int) $validated['supplier_id'],
            (int) $row['barang_id'],
        ])->all();
        $this->refreshPricePairs([...$oldPairs, ...$newPairs]);

        app(AuditLogger::class)->activity(
            $expense ? 'pengeluaran_belanja_detail_update' : 'pengeluaran_belanja_detail_create',
            ($expense ? 'Memperbarui' : 'Membuat').' detail transaksi belanja: '.$saved->namaSupplier(),
            auth()->user(),
            [
                'pengeluaran_belanja_id' => $saved->getKey(),
                'kalkulator_belanja_id' => $session->getKey(),
                'jumlah_barang' => count($itemRows),
                'jumlah_nota' => count($paths),
                'total' => $total,
            ],
        );

        return $saved->refresh()->load(['items.barang', 'notas', 'fotoBarangSessions', 'supplier']);
    }

    public function delete(PengeluaranBelanja $expense): bool
    {
        return (bool) $expense->delete();
    }

    /** @return array{price: ?int, date: ?string} */
    public function latestPrice(int $supplierId, int $barangId, bool $refresh = false): array
    {
        if ($supplierId < 1 || $barangId < 1) {
            return ['price' => null, 'date' => null];
        }

        if (! $refresh) {
            $cached = HargaBarangSupplier::query()
                ->where('supplier_id', $supplierId)
                ->where('barang_id', $barangId)
                ->first();

            if ($cached) {
                return [
                    'price' => (int) $cached->harga_terakhir,
                    'date' => $cached->tanggal_harga_terakhir->toDateString(),
                ];
            }
        }

        $latest = PengeluaranBelanjaItem::query()
            ->select([
                'pengeluaran_belanja_items.harga_satuan',
                'kalkulator_belanjas.tanggal',
            ])
            ->join(
                'pengeluaran_belanjas',
                'pengeluaran_belanjas.id',
                '=',
                'pengeluaran_belanja_items.pengeluaran_belanja_id',
            )
            ->join(
                'kalkulator_belanjas',
                'kalkulator_belanjas.id',
                '=',
                'pengeluaran_belanjas.kalkulator_belanja_id',
            )
            ->where('pengeluaran_belanjas.supplier_id', $supplierId)
            ->where('pengeluaran_belanja_items.barang_id', $barangId)
            ->orderByDesc('kalkulator_belanjas.tanggal')
            ->orderByDesc('pengeluaran_belanja_items.updated_at')
            ->orderByDesc('pengeluaran_belanja_items.id')
            ->first();

        if (! $latest) {
            HargaBarangSupplier::query()
                ->where('supplier_id', $supplierId)
                ->where('barang_id', $barangId)
                ->delete();

            return ['price' => null, 'date' => null];
        }

        HargaBarangSupplier::query()->updateOrCreate(
            ['supplier_id' => $supplierId, 'barang_id' => $barangId],
            [
                'harga_terakhir' => (int) $latest->harga_satuan,
                'tanggal_harga_terakhir' => (string) $latest->tanggal,
            ],
        );

        return [
            'price' => (int) $latest->harga_satuan,
            'date' => (string) $latest->tanggal,
        ];
    }

    /** @param array<int, array{0: int, 1: int}> $pairs */
    public function refreshPricePairs(array $pairs): void
    {
        collect($pairs)
            ->filter(fn (array $pair): bool => ($pair[0] ?? 0) > 0 && ($pair[1] ?? 0) > 0)
            ->unique(fn (array $pair): string => $pair[0].':'.$pair[1])
            ->each(fn (array $pair): array => $this->latestPrice($pair[0], $pair[1], true));
    }

    public function subtotal(mixed $quantity, int $unitPrice): int
    {
        $normalized = $this->normalizeQuantity($quantity);
        $calculated = max(0, (float) $normalized) * max(0, $unitPrice);

        if (! is_finite($calculated) || $calculated >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int) round($calculated);
    }

    private function normalizeQuantity(mixed $quantity): string
    {
        return number_format(round((float) $quantity, 3), 3, '.', '');
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return array<int, string>
     */
    private function validatedReceiptPaths(array $paths, ?PengeluaranBelanja $expense): array
    {
        $normalized = collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path))
            ->map(fn (string $path): string => trim(str_replace('\\', '/', $path)))
            ->filter()
            ->unique()
            ->values();

        foreach ($normalized as $index => $path) {
            $alreadyUsed = DB::table('pengeluaran_belanja_notas')
                ->where('path', $path)
                ->when($expense, fn ($query) => $query->where('pengeluaran_belanja_id', '!=', $expense->getKey()))
                ->exists();

            if (
                ! str_starts_with($path, 'nota-belanja/')
                || ! Storage::disk('public')->exists($path)
                || $alreadyUsed
            ) {
                throw ValidationException::withMessages([
                    "nota_paths.{$index}" => 'Foto nota tidak valid atau sudah digunakan transaksi lain.',
                ]);
            }
        }

        return $normalized->all();
    }
}
