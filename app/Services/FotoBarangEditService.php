<?php

namespace App\Services;

use App\Models\FotoBarangEdit;
use App\Models\FotoBarangItem;
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
}
