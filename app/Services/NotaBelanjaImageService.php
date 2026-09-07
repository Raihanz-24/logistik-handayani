<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class NotaBelanjaImageService
{
    private const MAX_DIMENSION = 1800;

    private const TARGET_FILE_SIZE = 900 * 1024;

    public function store(UploadedFile $file): string
    {
        $sourcePath = $file->getRealPath();
        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false) {
            throw new InvalidArgumentException('File nota bukan gambar yang valid.');
        }

        [$sourceWidth, $sourceHeight] = $imageInfo;

        if (($sourceWidth * $sourceHeight) > 40_000_000) {
            throw new InvalidArgumentException('Dimensi foto nota terlalu besar. Gunakan gambar di bawah 40 megapiksel.');
        }

        $source = $this->createImage($sourcePath, $imageInfo['mime'] ?? '');
        $source = $this->orientImage($source, $sourcePath, $imageInfo['mime'] ?? '');
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $optimized = imagecreatetruecolor($targetWidth, $targetHeight);

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

        $temporaryPath = tempnam(sys_get_temp_dir(), 'nota-belanja-');

        if ($temporaryPath === false) {
            imagedestroy($source);
            imagedestroy($optimized);

            throw new RuntimeException('Gagal menyiapkan file sementara untuk foto nota.');
        }

        $stream = null;

        try {
            for ($quality = 84; $quality >= 56; $quality -= 7) {
                if (! imagewebp($optimized, $temporaryPath, $quality)) {
                    throw new RuntimeException('Gagal mengompres foto nota.');
                }

                clearstatcache(true, $temporaryPath);

                if (filesize($temporaryPath) <= self::TARGET_FILE_SIZE) {
                    break;
                }
            }

            $path = 'nota-belanja/'.Str::uuid().'.webp';
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false || ! Storage::disk('public')->put($path, $stream)) {
                throw new RuntimeException('Gagal menyimpan foto nota.');
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
            throw new InvalidArgumentException('Gunakan foto nota berformat JPG, PNG, atau WebP.');
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
}
