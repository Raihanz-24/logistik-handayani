<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class BarangImageService
{
    private const MAX_DIMENSION = 1600;

    private const TARGET_FILE_SIZE = 700 * 1024;

    /**
     * Optimize an uploaded product image and store it as WebP.
     */
    public function store(UploadedFile $file): string
    {
        $sourcePath = $file->getRealPath();
        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false) {
            throw new InvalidArgumentException('File yang diunggah bukan gambar yang valid.');
        }

        [$sourceWidth, $sourceHeight] = $imageInfo;

        if (($sourceWidth * $sourceHeight) > 40_000_000) {
            throw new InvalidArgumentException('Dimensi gambar terlalu besar. Gunakan gambar di bawah 40 megapiksel.');
        }

        $source = $this->createImage($sourcePath, $imageInfo['mime'] ?? '');
        $source = $this->orientImage($source, $sourcePath, $imageInfo['mime'] ?? '');

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $optimized = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($optimized, false);
        imagesavealpha($optimized, true);
        $transparent = imagecolorallocatealpha($optimized, 0, 0, 0, 127);
        imagefill($optimized, 0, 0, $transparent);
        imagecopyresampled(
            $optimized,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        $temporaryPath = tempnam(sys_get_temp_dir(), 'barang-image-');

        if ($temporaryPath === false) {
            imagedestroy($source);
            imagedestroy($optimized);

            throw new RuntimeException('Gagal menyiapkan file sementara untuk gambar barang.');
        }

        $stream = null;

        try {
            $this->writeCompressedWebp($optimized, $temporaryPath);

            $path = 'barang/'.Str::uuid().'.webp';
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false || ! Storage::disk('public')->put($path, $stream)) {
                throw new RuntimeException('Gagal menyimpan gambar barang.');
            }

            return $path;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            imagedestroy($source);
            imagedestroy($optimized);

            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function createImage(string $path, string $mimeType): GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Gunakan gambar dengan format JPG, PNG, atau WebP.');
        }

        return $image;
    }

    private function orientImage(GdImage $image, string $path, string $mimeType): GdImage
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path)['Orientation'] ?? 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if (! $rotated instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function writeCompressedWebp(GdImage $image, string $path): void
    {
        for ($quality = 82; $quality >= 54; $quality -= 7) {
            if (! imagewebp($image, $path, $quality)) {
                throw new RuntimeException('Gagal mengompres gambar barang.');
            }

            clearstatcache(true, $path);

            if (filesize($path) <= self::TARGET_FILE_SIZE) {
                return;
            }
        }
    }
}
