<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicStorageController extends Controller
{
    /**
     * Serve files from the public disk without requiring a symbolic link.
     */
    public function __invoke(string $path): StreamedResponse
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        abort_if(
            $path === '' || str_contains($path, '..') || str_contains($path, "\0"),
            404,
        );

        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
