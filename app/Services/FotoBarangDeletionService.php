<?php

namespace App\Services;

use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FotoBarangDeletionService
{
    /**
     * @param  array<int, int>  $photoIds
     * @return array<int, int>
     */
    public function deleteMany(FotoBarangSession $session, array $photoIds): array
    {
        $ids = collect($photoIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() || $ids->count() > 100) {
            throw new RuntimeException('Pilih antara 1 sampai 100 foto untuk dihapus.');
        }

        $disk = Storage::disk('local');
        $trashDirectory = 'foto-barang-trash/'.Str::uuid();
        $movedFiles = [];
        $thumbnailPaths = [];

        if (! $disk->makeDirectory($trashDirectory)) {
            throw new RuntimeException('Folder pengamanan sementara tidak dapat dibuat.');
        }

        try {
            $deletedIds = DB::transaction(function () use (
                $session,
                $ids,
                $disk,
                $trashDirectory,
                &$movedFiles,
                &$thumbnailPaths,
            ): array {
                $lockedSession = FotoBarangSession::query()
                    ->lockForUpdate()
                    ->findOrFail($session->getKey());
                $photos = $lockedSession->items()
                    ->whereKey($ids->all())
                    ->with('edits')
                    ->lockForUpdate()
                    ->get();

                if ($photos->count() !== $ids->count()) {
                    throw new RuntimeException('Salah satu foto tidak ditemukan pada folder ini.');
                }

                foreach ($photos as $photo) {
                    $paths = collect([$photo->path])
                        ->merge($photo->edits->pluck('path'))
                        ->filter()
                        ->unique();

                    foreach ($paths as $path) {
                        if (! $disk->exists($path)) {
                            continue;
                        }

                        $trashPath = $trashDirectory.'/'.Str::uuid().'-'.basename($path);

                        if (! $disk->move($path, $trashPath)) {
                            throw new RuntimeException('Salah satu file tidak dapat diamankan sebelum dihapus.');
                        }

                        $movedFiles[$path] = $trashPath;
                    }

                    $thumbnailPrefix = $lockedSession->storageDirectory().'/.thumbnails/'.$photo->getKey().'-';
                    $thumbnailPaths = [
                        ...$thumbnailPaths,
                        ...array_values(array_filter(
                            $disk->files($lockedSession->storageDirectory().'/.thumbnails'),
                            fn (string $path): bool => str_starts_with($path, $thumbnailPrefix),
                        )),
                    ];

                    $photo->delete();
                }

                return $photos->modelKeys();
            });

        } catch (Throwable $exception) {
            foreach (array_reverse($movedFiles, true) as $originalPath => $trashPath) {
                if (! $disk->exists($trashPath) || $disk->exists($originalPath)) {
                    continue;
                }

                $disk->makeDirectory(dirname($originalPath));
                $disk->move($trashPath, $originalPath);
            }

            $disk->deleteDirectory($trashDirectory);

            throw $exception;
        }

        try {
            if ($thumbnailPaths !== []) {
                $disk->delete(array_values(array_unique($thumbnailPaths)));
            }

            $disk->deleteDirectory($trashDirectory);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }

        return array_map('intval', $deletedIds);
    }
}
