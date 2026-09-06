<?php

namespace App\Services;

use App\Models\FotoBarangEdit;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FotoBarangEditService
{
    public function __construct(private readonly FotoBarangImageService $imageService) {}

    public function create(FotoBarangItem $photo, CarbonInterface $revisedAt, ?User $user): FotoBarangEdit
    {
        $photo->loadMissing('session');
        $rendered = $this->imageService->renderTimeRevision($photo, $revisedAt);
        $storedPath = $photo->session->storageDirectory().'/hasil-edit/'.sprintf(
            '%03d-%s-%s.jpg',
            $photo->urutan,
            $revisedAt->setTimezone('Asia/Jakarta')->format('Ymd-His'),
            Str::lower(Str::random(8)),
        );

        try {
            $stream = fopen($rendered['path'], 'rb');

            if ($stream === false) {
                throw new RuntimeException('File hasil edit tidak dapat dibaca.');
            }

            try {
                if (! Storage::disk('local')->put($storedPath, $stream)) {
                    throw new RuntimeException('Hasil edit gagal disimpan. Foto asli tetap aman.');
                }
            } finally {
                fclose($stream);
            }

            return DB::transaction(fn (): FotoBarangEdit => FotoBarangEdit::query()->create([
                'foto_barang_item_id' => $photo->getKey(),
                'user_id' => $user?->getKey(),
                'path' => $storedPath,
                'waktu_baru' => $revisedAt->setTimezone('Asia/Jakarta'),
            ]));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        } finally {
            if (is_file($rendered['path'])) {
                unlink($rendered['path']);
            }
        }
    }

    /** @return array{item: FotoBarangItem, duplicate: bool} */
    public function copyToSession(FotoBarangEdit $edit, FotoBarangSession $destination): array
    {
        $result = $this->copyManyToSession([$edit], $destination);

        return [
            'item' => $result['items'][0],
            'duplicate' => $result['duplicate_count'] === 1,
        ];
    }

    /**
     * @param  iterable<FotoBarangEdit>  $edits
     * @return array{items: array<int, FotoBarangItem>, copied_count: int, duplicate_count: int}
     */
    public function copyManyToSession(iterable $edits, FotoBarangSession $destination): array
    {
        $disk = Storage::disk('local');
        $prepared = collect($edits)
            ->filter(fn (mixed $edit): bool => $edit instanceof FotoBarangEdit)
            ->unique(fn (FotoBarangEdit $edit): int => (int) $edit->getKey())
            ->values()
            ->map(function (FotoBarangEdit $edit) use ($disk): array {
                $edit->loadMissing('photo');
                $sourcePhoto = $edit->photo;

                if (! $sourcePhoto instanceof FotoBarangItem) {
                    throw new RuntimeException('Salah satu foto sumber hasil edit tidak ditemukan.');
                }

                if (! filled($edit->path) || ! $disk->exists($edit->path)) {
                    throw new RuntimeException('Salah satu file hasil edit tidak ditemukan di server.');
                }

                $imageInfo = @getimagesize($disk->path($edit->path));

                if ($imageInfo === false || (string) ($imageInfo['mime'] ?? '') !== 'image/jpeg') {
                    throw new RuntimeException('Salah satu hasil edit bukan JPEG yang valid.');
                }

                return [
                    'edit_id' => (int) $edit->getKey(),
                    'source_path' => $edit->path,
                    'client_capture_id' => 'edit-copy:'.$edit->getKey(),
                    'captured_at' => $edit->waktu_baru ?? $sourcePhoto->diambil_at ?? now('Asia/Jakarta'),
                    'file_size' => max(0, (int) ($disk->size($edit->path) ?: 0)),
                    'width' => max(1, (int) ($imageInfo[0] ?? 0)),
                    'height' => max(1, (int) ($imageInfo[1] ?? 0)),
                    'latitude' => $sourcePhoto->latitude,
                    'longitude' => $sourcePhoto->longitude,
                    'accuracy' => $sourcePhoto->akurasi_meter,
                ];
            });

        if ($prepared->isEmpty()) {
            throw new RuntimeException('Pilih minimal satu hasil edit untuk disalin.');
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $destination,
                $disk,
                $prepared,
                &$storedPaths,
            ): array {
                $lockedSession = FotoBarangSession::query()
                    ->lockForUpdate()
                    ->findOrFail($destination->getKey());
                $existingItems = $lockedSession->items()
                    ->whereIn('client_capture_id', $prepared->pluck('client_capture_id'))
                    ->get()
                    ->keyBy('client_capture_id');
                $sequence = (int) $lockedSession->items()->max('urutan');
                $items = [];
                $copiedCount = 0;
                $duplicateCount = 0;

                foreach ($prepared as $photo) {
                    $existing = $existingItems->get($photo['client_capture_id']);

                    if ($existing instanceof FotoBarangItem) {
                        $items[] = $existing;
                        $duplicateCount++;

                        continue;
                    }

                    $sequence++;
                    $capturedAt = $photo['captured_at'];
                    $storedPath = $lockedSession->storageDirectory().'/'.sprintf(
                        '%03d-%s-edited-copy-%s.jpg',
                        $sequence,
                        $capturedAt->setTimezone('Asia/Jakarta')->format('Ymd-His'),
                        Str::lower(Str::random(8)),
                    );

                    if (! $disk->copy($photo['source_path'], $storedPath)) {
                        throw new RuntimeException('Salah satu hasil edit gagal disalin ke folder tujuan.');
                    }

                    $storedPaths[] = $storedPath;
                    $items[] = $lockedSession->items()->create([
                        'client_capture_id' => $photo['client_capture_id'],
                        'urutan' => $sequence,
                        'path' => $storedPath,
                        'processing_status' => FotoBarangItem::PROCESSING_COMPLETED,
                        'processing_attempts' => 0,
                        'processing_error' => null,
                        'processed_at' => now('Asia/Jakarta'),
                        'latitude' => $photo['latitude'],
                        'longitude' => $photo['longitude'],
                        'akurasi_meter' => $photo['accuracy'],
                        'diambil_at' => $capturedAt,
                        'ukuran_asli' => $photo['file_size'],
                        'ukuran_hasil' => $photo['file_size'],
                        'lebar' => $photo['width'],
                        'tinggi' => $photo['height'],
                    ]);
                    $copiedCount++;
                }

                return [
                    'items' => $items,
                    'copied_count' => $copiedCount,
                    'duplicate_count' => $duplicateCount,
                ];
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                $disk->delete($storedPaths);
            }

            throw $exception;
        }
    }
}
