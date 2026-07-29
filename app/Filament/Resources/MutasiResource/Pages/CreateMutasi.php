<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateMutasi extends CreateRecord
{
    protected static string $resource = MutasiResource::class;

    protected int $createdCount = 0;

    protected function beforeCreate(): void
    {
        $data = $this->form->getState();
        $lokasiId = (int) ($data['lokasi_id'] ?? 0);

        if (! Lokasi::query()->gudang()->whereKey($lokasiId)->exists()) {
            Notification::make()
                ->title('Gudang tidak valid')
                ->body('Mutasi stok hanya dapat menggunakan lokasi bertipe Gudang.')
                ->danger()
                ->send();

            throw new Halt;
        }

        $items = collect($data['items'] ?? []);
        $barangIds = $items
            ->pluck('barang_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);

        if ($barangIds->count() !== $barangIds->unique()->count()) {
            Notification::make()
                ->title('Barang tidak boleh duplikat')
                ->body('Setiap barang hanya boleh dipilih satu kali dalam satu pengajuan mutasi.')
                ->danger()
                ->send();

            throw new Halt;
        }

        if (($data['jenis_mutasi'] ?? null) !== 'keluar') {
            return;
        }

        $lokasiTujuanId = (int) ($data['lokasi_tujuan_id'] ?? 0);
        if (
            ! $lokasiTujuanId
            || $lokasiTujuanId === $lokasiId
            || ! Lokasi::query()->whereKey($lokasiTujuanId)->exists()
        ) {
            Notification::make()
                ->title('Lokasi tujuan tidak valid')
                ->body('Pilih lokasi tujuan yang berbeda dari gudang asal.')
                ->danger()
                ->send();

            throw new Halt;
        }

        $namaBarang = Barang::query()
            ->whereIn('id', $barangIds)
            ->pluck('nama_barang', 'id');

        foreach ($items as $item) {
            $barangId = (int) ($item['barang_id'] ?? 0);
            $jumlah = (int) ($item['jumlah'] ?? 0);

            if (! $barangId || $jumlah <= 0) {
                continue;
            }

            $stok = MutasiResource::getStokTersedia($barangId, $lokasiId);
            if ($jumlah <= $stok) {
                continue;
            }

            $barang = $namaBarang->get($barangId, "Barang ID {$barangId}");

            Notification::make()
                ->title('Stok tidak mencukupi')
                ->body("{$barang}: stok tersedia {$stok}, diminta {$jumlah}.")
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $items = collect($data['items'] ?? []);
        unset($data['items'], $data['barang_id'], $data['jumlah']);

        return DB::transaction(function () use ($data, $items): Mutasi {
            $firstRecord = null;
            $actorId = (int) auth()->id();

            foreach ($items as $item) {
                $record = Mutasi::query()->create([
                    ...$data,
                    'status' => 'pending',
                    'user_id' => $actorId,
                    'created_by' => $actorId,
                    'barang_id' => (int) $item['barang_id'],
                    'jumlah' => (int) $item['jumlah'],
                ]);

                $firstRecord ??= $record;
                $this->createdCount++;
            }

            if (! $firstRecord) {
                throw new \RuntimeException('Minimal satu barang wajib ditambahkan.');
            }

            return $firstRecord;
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "{$this->createdCount} mutasi barang berhasil dibuat dan menunggu approval.";
    }

    protected function getRedirectUrl(): string
    {
        return MutasiResource::getUrl('index');
    }
}
