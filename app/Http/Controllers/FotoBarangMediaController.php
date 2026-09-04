<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessFotoBarangImage;
use App\Models\FotoBarangEdit;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FotoBarangImageService;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class FotoBarangMediaController extends Controller
{
    public function store(
        Request $request,
        FotoBarangSession $session,
        FotoBarangImageService $imageService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $this->authorizeAccess($request, $session);
        abort_unless($session->isActive(), 422, 'Sesi foto sudah selesai.');

        $validated = $request->validate([
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('foto_barang.max_upload_kb', 10240),
            ],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'captured_at' => ['nullable', 'date'],
            'client_capture_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], [
            'photo.max' => 'Foto asli maksimal 10 MB.',
            'photo.image' => 'File yang dikirim bukan gambar yang valid.',
        ]);

        $capturedAt = filled($validated['captured_at'] ?? null)
            ? CarbonImmutable::parse($validated['captured_at'])->setTimezone('Asia/Jakarta')
            : CarbonImmutable::now('Asia/Jakarta');

        $item = $imageService->stage(
            $session,
            $request->file('photo'),
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['accuracy']) ? (int) $validated['accuracy'] : null,
            $capturedAt,
            (string) $validated['client_capture_id'],
        );

        if ($item->wasRecentlyCreated) {
            $auditLogger->activity(
                'foto_barang_create',
                "Menambahkan foto ke sesi: {$session->judul}",
                $request->user(),
                [
                    'session_id' => $session->getKey(),
                    'photo_id' => $item->getKey(),
                    'sequence' => $item->urutan,
                ],
            );

            $this->dispatchPhotoProcessing($item);
        }

        return response()->json([
            'saved' => true,
            'photo_id' => $item->getKey(),
            'sequence' => $item->urutan,
            'duplicate' => ! $item->wasRecentlyCreated,
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function preview(
        Request $request,
        FotoBarangSession $session,
        FotoBarangItem $photo,
    ): StreamedResponse {
        $this->authorizeAccess($request, $session, $photo);

        return $this->disk()->response($photo->path, $photo->fileName(), [
            'Cache-Control' => 'private, max-age=86400, stale-while-revalidate=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function thumbnail(
        Request $request,
        FotoBarangSession $session,
        FotoBarangItem $photo,
    ): StreamedResponse {
        $this->authorizeAccess($request, $session, $photo);

        try {
            $thumbnailPath = $this->ensureThumbnail($session, $photo);

            if ($thumbnailPath !== null && $this->disk()->exists($thumbnailPath)) {
                return $this->disk()->response(
                    $thumbnailPath,
                    'thumbnail-'.pathinfo($photo->fileName(), PATHINFO_FILENAME).'.jpg',
                    [
                        'Cache-Control' => 'private, max-age=604800, stale-while-revalidate=86400',
                        'X-Content-Type-Options' => 'nosniff',
                    ],
                    'inline',
                );
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->disk()->response($photo->path, $photo->fileName(), [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function download(
        Request $request,
        FotoBarangSession $session,
        FotoBarangItem $photo,
    ): StreamedResponse {
        $this->authorizeAccess($request, $session, $photo);

        return $this->disk()->download($photo->path, $photo->fileName(), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function previewEdit(
        Request $request,
        FotoBarangSession $session,
        FotoBarangEdit $edit,
    ): StreamedResponse {
        $this->authorizeEditAccess($request, $session, $edit);

        return $this->disk()->response($edit->path, $edit->fileName(), [
            'Cache-Control' => 'private, max-age=86400, stale-while-revalidate=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function downloadEdit(
        Request $request,
        FotoBarangSession $session,
        FotoBarangEdit $edit,
    ): StreamedResponse {
        $this->authorizeEditAccess($request, $session, $edit);

        return $this->disk()->download($edit->path, $edit->fileName(), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function archive(Request $request, FotoBarangSession $session): BinaryFileResponse
    {
        $this->authorizeAccess($request, $session);
        $session->loadMissing('items');

        abort_if($session->items->isEmpty(), 422, 'Sesi ini belum memiliki foto.');

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi ZIP belum aktif pada server.');
        }

        $directory = storage_path('app/temp-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder sementara unduhan tidak dapat dibuat.');
        }

        $zipPath = $directory.'/foto-barang-'.bin2hex(random_bytes(8)).'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Arsip foto tidak dapat dibuat.');
        }

        try {
            foreach ($session->items as $photo) {
                if ($this->disk()->exists($photo->path)) {
                    $zip->addFile($this->disk()->path($photo->path), $photo->fileName());
                }
            }
        } finally {
            $zip->close();
        }

        $fileName = Str::slug($session->judul ?: $session->code()).'-'.$session->code().'.zip';

        return response()
            ->download($zipPath, $fileName, [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }

    private function authorizeAccess(
        Request $request,
        FotoBarangSession $session,
        ?FotoBarangItem $photo = null,
    ): void {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasRole('super_admin') || $session->user_id === $user->getKey(), 403);

        if ($photo !== null) {
            abort_unless($photo->foto_barang_session_id === $session->getKey(), 404);
        }

        abort_unless($photo === null || $this->disk()->exists($photo->path), 404);
    }

    private function authorizeEditAccess(
        Request $request,
        FotoBarangSession $session,
        FotoBarangEdit $edit,
    ): void {
        $edit->loadMissing('photo');
        $photo = $edit->photo;

        abort_unless($photo instanceof FotoBarangItem, 404);
        abort_unless($photo->foto_barang_session_id === $session->getKey(), 404);
        $this->authorizeAccess($request, $session);
        abort_unless($this->disk()->exists($edit->path), 404);
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    private function ensureThumbnail(FotoBarangSession $session, FotoBarangItem $photo): ?string
    {
        $disk = $this->disk();
        $thumbnailPath = $session->storageDirectory().'/.thumbnails/'
            .$photo->getKey().'-'.substr(sha1($photo->path), 0, 12).'.jpg';
        $sourceModifiedAt = $disk->lastModified($photo->path);

        if ($disk->exists($thumbnailPath) && $disk->lastModified($thumbnailPath) >= $sourceModifiedAt) {
            return $thumbnailPath;
        }

        return Cache::lock('foto-barang-thumbnail:'.$photo->getKey(), 15)->block(5, function () use (
            $disk,
            $photo,
            $sourceModifiedAt,
            $thumbnailPath,
        ): ?string {
            if ($disk->exists($thumbnailPath) && $disk->lastModified($thumbnailPath) >= $sourceModifiedAt) {
                return $thumbnailPath;
            }

            $sourcePath = $disk->path($photo->path);
            $info = @getimagesize($sourcePath);

            if ($info === false) {
                return null;
            }

            $source = match ((string) ($info['mime'] ?? '')) {
                'image/jpeg' => @imagecreatefromjpeg($sourcePath),
                'image/png' => @imagecreatefrompng($sourcePath),
                'image/webp' => @imagecreatefromwebp($sourcePath),
                default => false,
            };

            if ($source === false) {
                return null;
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'foto-thumb-');

            if ($temporaryPath === false) {
                imagedestroy($source);

                return null;
            }

            try {
                $width = max(1, (int) ($info[0] ?? 1));
                $height = max(1, (int) ($info[1] ?? 1));
                $maximumDimension = max(240, (int) config('foto_barang.thumbnail_dimension', 480));
                $scale = min(1, $maximumDimension / max($width, $height));
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
                $background = imagecolorallocate($thumbnail, 15, 23, 42);
                imagefill($thumbnail, 0, 0, $background);
                imagecopyresampled(
                    $thumbnail,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $width,
                    $height,
                );
                imageinterlace($thumbnail, true);
                $written = imagejpeg(
                    $thumbnail,
                    $temporaryPath,
                    min(88, max(55, (int) config('foto_barang.thumbnail_quality', 76))),
                );
                imagedestroy($thumbnail);

                if (! $written || ! is_file($temporaryPath) || filesize($temporaryPath) < 100) {
                    return null;
                }

                $stream = fopen($temporaryPath, 'rb');

                if ($stream === false) {
                    return null;
                }

                try {
                    return $disk->put($thumbnailPath, $stream) ? $thumbnailPath : null;
                } finally {
                    fclose($stream);
                }
            } finally {
                imagedestroy($source);

                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        });
    }

    private function dispatchPhotoProcessing(FotoBarangItem $item): void
    {
        $queue = (string) config('foto_barang.processing_queue', 'default');

        if (config('foto_barang.processing_mode') === 'queue') {
            ProcessFotoBarangImage::dispatch((int) $item->getKey())->onQueue($queue);

            return;
        }

        ProcessFotoBarangImage::dispatchAfterResponse((int) $item->getKey())->onQueue($queue);
    }
}
