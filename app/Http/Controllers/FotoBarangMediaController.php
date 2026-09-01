<?php

namespace App\Http\Controllers;

use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class FotoBarangMediaController extends Controller
{
    public function preview(
        Request $request,
        FotoBarangSession $session,
        FotoBarangItem $photo,
    ): StreamedResponse {
        $this->authorizeAccess($request, $session, $photo);

        return $this->disk()->response($photo->path, $photo->fileName(), [
            'Cache-Control' => 'private, max-age=86400',
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

    private function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }
}
