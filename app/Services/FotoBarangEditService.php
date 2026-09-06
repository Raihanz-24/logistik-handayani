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
        $edit->loadMissing('photo');
        $sourcePhoto = $edit->photo;

        if (! $sourcePhoto instanceof FotoBarangItem) {
            throw new RuntimeException('Foto sumber hasil edit tidak ditemukan.');
        }

        $disk = Storage::disk('local');

        if (! filled($edit->path) || ! $disk->exists($edit->path)) {
            throw new RuntimeException('File hasil edit tidak ditemukan di server.');
        }

        $sourcePath = $disk->path($edit->path);
        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false || (string) ($imageInfo['mime'] ?? '') !== 'image/jpeg') {
            throw new RuntimeException('File hasil edit bukan JPEG yang valid.');
        }

        $fileSize = max(0, (int) ($disk->size($edit->path) ?: 0));
        $clientCaptureId = 'edit-copy:'.$edit->getKey();
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $destination,
                $edit,
                $sourcePhoto,
                $disk,
                $imageInfo,
                $fileSize,
                $clientCaptureId,
                &$storedPath,
            ): array {
                $lockedSession = FotoBarangSession::query()
                    ->lockForUpdate()
                    ->findOrFail($destination->getKey());
                $existing = $lockedSession->items()
                    ->where('client_capture_id', $clientCaptureId)
                    ->first();

                if ($existing instanceof FotoBarangItem) {
                    return ['item' => $existing, 'duplicate' => true];
                }

                $sequence = ((int) $lockedSession->items()->max('urutan')) + 1;
                $capturedAt = $edit->waktu_baru ?? $sourcePhoto->diambil_at ?? now('Asia/Jakarta');
                $storedPath = $lockedSession->storageDirectory().'/'.sprintf(
                    '%03d-%s-edited-copy-%s.jpg',
                    $sequence,
                    $capturedAt->setTimezone('Asia/Jakarta')->format('Ymd-His'),
                    Str::lower(Str::random(8)),
                );

                if (! $disk->copy($edit->path, $storedPath)) {
                    throw new RuntimeException('Foto hasil edit gagal disalin ke folder tujuan.');
                }

                $item = $lockedSession->items()->create([
                    'client_capture_id' => $clientCaptureId,
                    'urutan' => $sequence,
                    'path' => $storedPath,
                    'processing_status' => FotoBarangItem::PROCESSING_COMPLETED,
                    'processing_attempts' => 0,
                    'processing_error' => null,
                    'processed_at' => now('Asia/Jakarta'),
                    'latitude' => $sourcePhoto->latitude,
                    'longitude' => $sourcePhoto->longitude,
                    'akurasi_meter' => $sourcePhoto->akurasi_meter,
                    'diambil_at' => $capturedAt,
                    'ukuran_asli' => $fileSize,
                    'ukuran_hasil' => $fileSize,
                    'lebar' => max(1, (int) ($imageInfo[0] ?? 0)),
                    'tinggi' => max(1, (int) ($imageInfo[1] ?? 0)),
                ]);

                return ['item' => $item, 'duplicate' => false];
            });
        } catch (Throwable $exception) {
            if (filled($storedPath)) {
                $disk->delete($storedPath);
            }

            throw $exception;
        }
    }
}
