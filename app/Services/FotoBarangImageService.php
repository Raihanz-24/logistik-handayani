<?php

namespace App\Services;

use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use Carbon\CarbonInterface;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FotoBarangImageService
{
    public function store(
        FotoBarangSession $session,
        UploadedFile $file,
        float $latitude,
        float $longitude,
        ?int $accuracy = null,
        ?CarbonInterface $capturedAt = null,
        ?string $clientCaptureId = null,
    ): FotoBarangItem {
        return $this->process($this->stage(
            $session,
            $file,
            $latitude,
            $longitude,
            $accuracy,
            $capturedAt,
            $clientCaptureId,
        ));
    }

    public function stage(
        FotoBarangSession $session,
        UploadedFile $file,
        float $latitude,
        float $longitude,
        ?int $accuracy = null,
        ?CarbonInterface $capturedAt = null,
        ?string $clientCaptureId = null,
    ): FotoBarangItem {
        $capturedAt ??= now('Asia/Jakarta');
        $sourcePath = $file->getRealPath();
        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false) {
            throw new InvalidArgumentException('File yang dipilih bukan gambar yang valid.');
        }

        [$width, $height] = $imageInfo;

        if (($width * $height) > 45_000_000) {
            throw new InvalidArgumentException('Resolusi foto terlalu besar. Gunakan kamera dengan resolusi maksimal 45 megapiksel.');
        }

        $extension = match ((string) ($imageInfo['mime'] ?? '')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Gunakan foto berformat JPG, PNG, atau WebP.'),
        };
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $session,
                $file,
                $latitude,
                $longitude,
                $accuracy,
                $capturedAt,
                $extension,
                $width,
                $height,
                $clientCaptureId,
                &$storedPath,
            ): FotoBarangItem {
                $lockedSession = FotoBarangSession::query()
                    ->lockForUpdate()
                    ->findOrFail($session->getKey());

                if (! $lockedSession->isActive()) {
                    throw new RuntimeException('Sesi foto sudah selesai dan tidak dapat ditambah foto baru.');
                }

                if (filled($clientCaptureId)) {
                    $existing = $lockedSession->items()
                        ->where('client_capture_id', $clientCaptureId)
                        ->first();

                    if ($existing) {
                        return $existing;
                    }
                }

                $sequence = ((int) $lockedSession->items()->max('urutan')) + 1;
                $storedPath = $lockedSession->storageDirectory().'/'.sprintf(
                    '%03d-%s-source-%s.%s',
                    $sequence,
                    $capturedAt->format('Ymd-His'),
                    Str::lower(Str::random(8)),
                    $extension,
                );
                $stream = fopen($file->getRealPath(), 'rb');

                if ($stream === false) {
                    throw new RuntimeException('File sumber foto tidak dapat dibaca.');
                }

                try {
                    if (! Storage::disk('local')->put($storedPath, $stream)) {
                        throw new RuntimeException('Foto sumber gagal disimpan. Silakan ulangi pengambilan foto.');
                    }
                } finally {
                    fclose($stream);
                }

                return $lockedSession->items()->create([
                    'client_capture_id' => $clientCaptureId,
                    'urutan' => $sequence,
                    'path' => $storedPath,
                    'processing_status' => FotoBarangItem::PROCESSING_PENDING,
                    'processing_attempts' => 0,
                    'processing_error' => null,
                    'processed_at' => null,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'akurasi_meter' => $accuracy,
                    'diambil_at' => $capturedAt,
                    'ukuran_asli' => max(0, (int) $file->getSize()),
                    'ukuran_hasil' => max(0, (int) $file->getSize()),
                    'lebar' => $width,
                    'tinggi' => $height,
                ]);
            });
        } catch (Throwable $exception) {
            if (filled($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function process(FotoBarangItem $item): FotoBarangItem
    {
        $item->loadMissing('session');

        if ($item->processingCompleted()) {
            return $item;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($item->path)) {
            throw new RuntimeException('File sumber foto tidak ditemukan.');
        }

        $sourcePath = $disk->path($item->path);
        $mimeType = (string) (@mime_content_type($sourcePath) ?: 'application/octet-stream');
        $file = new UploadedFile($sourcePath, basename($item->path), $mimeType, null, true);
        $capturedAt = $item->diambil_at ?? now('Asia/Jakarta');
        $rendered = $this->render(
            $file,
            $item->session,
            (float) $item->latitude,
            (float) $item->longitude,
            $item->akurasi_meter,
            $capturedAt,
        );
        $processedPath = $item->session->storageDirectory().'/'.sprintf(
            '%03d-%s-processed-%s.jpg',
            $item->urutan,
            $capturedAt->format('Ymd-His'),
            Str::lower(Str::random(8)),
        );

        try {
            $stream = fopen($rendered['path'], 'rb');

            if ($stream === false) {
                throw new RuntimeException('File hasil kompresi tidak dapat dibaca.');
            }

            try {
                if (! $disk->put($processedPath, $stream)) {
                    throw new RuntimeException('Hasil kompresi gagal disimpan.');
                }
            } finally {
                fclose($stream);
            }

            $this->validateProcessedFile($disk->path($processedPath), $rendered);
            $sourceStoragePath = $item->path;

            try {
                $updated = DB::transaction(function () use ($item, $processedPath, $rendered): FotoBarangItem {
                    $lockedItem = FotoBarangItem::query()->lockForUpdate()->findOrFail($item->getKey());

                    if ($lockedItem->processingCompleted()) {
                        return $lockedItem;
                    }

                    $lockedItem->update([
                        'path' => $processedPath,
                        'processing_status' => FotoBarangItem::PROCESSING_COMPLETED,
                        'processing_error' => null,
                        'processed_at' => now('Asia/Jakarta'),
                        'ukuran_hasil' => $rendered['file_size'],
                        'lebar' => $rendered['width'],
                        'tinggi' => $rendered['height'],
                    ]);

                    return $lockedItem->fresh();
                });
            } catch (Throwable $exception) {
                $disk->delete($processedPath);

                throw $exception;
            }

            if ($updated->path !== $processedPath) {
                $disk->delete($processedPath);
            } elseif ($sourceStoragePath !== $processedPath) {
                $disk->delete($sourceStoragePath);
            }

            return $updated;
        } catch (Throwable $exception) {
            if ($disk->exists($processedPath)) {
                $disk->delete($processedPath);
            }

            throw $exception;
        } finally {
            if (is_file($rendered['path'])) {
                unlink($rendered['path']);
            }
        }
    }

    /** @param array{file_size: int, width: int, height: int} $rendered */
    private function validateProcessedFile(string $path, array $rendered): void
    {
        clearstatcache(true, $path);
        $info = @getimagesize($path);

        if (
            $info === false
            || ($info['mime'] ?? null) !== 'image/jpeg'
            || (int) ($info[0] ?? 0) !== $rendered['width']
            || (int) ($info[1] ?? 0) !== $rendered['height']
            || ! is_file($path)
            || filesize($path) < 1024
        ) {
            throw new RuntimeException('Hasil kompresi tidak valid; foto sumber tetap dipertahankan.');
        }
    }

    /**
     * @return array{path: string, original_size: int, file_size: int, width: int, height: int}
     */
    private function render(
        UploadedFile $file,
        FotoBarangSession $session,
        float $latitude,
        float $longitude,
        ?int $accuracy,
        CarbonInterface $capturedAt,
    ): array {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            throw new RuntimeException('Ekstensi GD dengan dukungan JPEG wajib diaktifkan pada server.');
        }

        $sourcePath = $file->getRealPath();
        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false) {
            throw new InvalidArgumentException('File yang dipilih bukan gambar yang valid.');
        }

        [$sourceWidth, $sourceHeight] = $imageInfo;

        if (($sourceWidth * $sourceHeight) > 45_000_000) {
            throw new InvalidArgumentException('Resolusi foto terlalu besar. Gunakan kamera dengan resolusi maksimal 45 megapiksel.');
        }

        $source = $this->createImage($sourcePath, (string) ($imageInfo['mime'] ?? ''));
        $source = $this->orientImage($source, $sourcePath, (string) ($imageInfo['mime'] ?? ''));
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $maxDimension = max(800, (int) config('foto_barang.max_dimension', 1920));
        $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled(
            $canvas,
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
        imagedestroy($source);

        $this->addLocationOverlay(
            $canvas,
            $session,
            $latitude,
            $longitude,
            $accuracy,
            $capturedAt,
        );

        $temporaryPath = tempnam(sys_get_temp_dir(), 'foto-barang-');

        if ($temporaryPath === false) {
            imagedestroy($canvas);

            throw new RuntimeException('Gagal menyiapkan file sementara untuk foto.');
        }

        try {
            $this->writeCompressedJpeg($canvas, $temporaryPath);

            return [
                'path' => $temporaryPath,
                'original_size' => max(0, (int) $file->getSize()),
                'file_size' => max(0, (int) filesize($temporaryPath)),
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $exception;
        } finally {
            imagedestroy($canvas);
        }
    }

    private function addLocationOverlay(
        GdImage $image,
        FotoBarangSession $session,
        float $latitude,
        float $longitude,
        ?int $accuracy,
        CarbonInterface $capturedAt,
    ): void {
        $width = imagesx($image);
        $height = imagesy($image);
        $margin = max(18, (int) round($width * 0.024));
        $overlayHeight = (int) min(max(285, $height * 0.32), max(285, $height * 0.42));
        $overlayTop = $height - $overlayHeight - $margin;
        $overlayLeft = $margin;
        $overlayRight = $width - $margin;
        $fontRegular = $this->fontPath(false);
        $fontBold = $this->fontPath(true) ?? $fontRegular;

        imagealphablending($image, true);
        $overlay = imagecolorallocatealpha($image, 5, 10, 18, 34);
        $badge = imagecolorallocatealpha($image, 5, 10, 18, 42);
        $white = imagecolorallocate($image, 255, 255, 255);
        $softWhite = imagecolorallocate($image, 226, 232, 240);
        $amber = imagecolorallocate($image, 245, 158, 11);
        $red = imagecolorallocate($image, 220, 38, 38);

        imagefilledrectangle($image, $overlayLeft, $overlayTop, $overlayRight, $height - $margin, $overlay);

        $badgeText = 'HANDAYANI MAP CAMERA';
        $badgeSize = max(13, (int) round($width * 0.016));
        $badgeWidth = $this->textWidth($badgeText, $badgeSize, $fontBold) + ($margin * 2);
        $badgeTop = max($margin, $overlayTop - (int) round($badgeSize * 2.25));
        imagefilledrectangle(
            $image,
            $overlayRight - $badgeWidth,
            $badgeTop,
            $overlayRight,
            $overlayTop - 7,
            $badge,
        );
        imagefilledellipse(
            $image,
            (int) ($overlayRight - $badgeWidth + ($margin * 0.62)),
            (int) ($badgeTop + (($overlayTop - 7 - $badgeTop) / 2)),
            max(8, (int) ($badgeSize * 0.7)),
            max(8, (int) ($badgeSize * 0.7)),
            $amber,
        );
        $this->drawText(
            $image,
            $badgeText,
            $badgeSize,
            (int) ($overlayRight - $badgeWidth + $margin),
            (int) ($overlayTop - 15),
            $white,
            $fontBold,
        );

        $timeSize = max(36, (int) round($width * 0.064));
        $dateSize = max(23, (int) round($width * 0.038));
        $locationSize = max(20, (int) round($width * 0.031));
        $detailSize = max(15, (int) round($width * 0.020));
        $contentLeft = $overlayLeft + $margin;
        $firstLineBaseline = $overlayTop + max(70, (int) round($overlayHeight * 0.30));
        $timeText = $capturedAt->format('H:i').' WIB';
        $timeWidth = $this->textWidth($timeText, $timeSize, $fontBold);

        $this->drawText($image, $timeText, $timeSize, $contentLeft, $firstLineBaseline, $white, $fontBold);

        $separatorX = min(
            $overlayRight - 260,
            $contentLeft + $timeWidth + max(18, (int) round($width * 0.018)),
        );
        imagefilledrectangle(
            $image,
            $separatorX,
            $overlayTop + 22,
            $separatorX + max(4, (int) round($width * 0.004)),
            $firstLineBaseline + 8,
            $amber,
        );

        $dateX = $separatorX + max(18, (int) round($width * 0.018));
        $dateText = $capturedAt->locale('id')->translatedFormat('d M Y');
        $dayText = $capturedAt->locale('id')->translatedFormat('l');
        $this->drawText($image, $dateText, $dateSize, $dateX, $overlayTop + 49, $white, $fontBold);
        $this->drawText($image, $dayText, $dateSize, $dateX, $firstLineBaseline, $white, $fontBold);

        $locationBaseline = $firstLineBaseline + max(37, (int) round($locationSize * 1.45));
        $location = $this->fitText(
            (string) $session->nama_lokasi,
            $locationSize,
            $fontBold,
            ($overlayRight - $contentLeft) - 50,
        );
        $this->drawText($image, $location, $locationSize, $contentLeft, $locationBaseline, $white, $fontBold);

        $flagX = min(
            $overlayRight - 34,
            $contentLeft + $this->textWidth($location, $locationSize, $fontBold) + 12,
        );
        $flagTop = $locationBaseline - $locationSize;
        imagefilledrectangle($image, $flagX, $flagTop, $flagX + 28, $flagTop + 10, $red);
        imagefilledrectangle($image, $flagX, $flagTop + 10, $flagX + 28, $flagTop + 20, $white);

        $addressLines = $this->wrapText(
            (string) $session->alamat,
            $detailSize,
            $fontRegular,
            ($overlayRight - $contentLeft) - $margin,
            2,
        );
        $detailBaseline = $locationBaseline + max(28, (int) round($detailSize * 1.55));

        foreach ($addressLines as $line) {
            $this->drawText($image, $line, $detailSize, $contentLeft, $detailBaseline, $softWhite, $fontRegular);
            $detailBaseline += max(21, (int) round($detailSize * 1.35));
        }

        $coordinateText = sprintf('Lat %.6f  Long %.6f', $latitude, $longitude);

        if ($accuracy !== null) {
            $coordinateText .= "  Akurasi +/- {$accuracy} m";
        }

        $coordinateText = $this->fitText(
            $coordinateText,
            $detailSize,
            $fontRegular,
            ($overlayRight - $contentLeft) - $margin,
        );
        $coordinateBaseline = min($height - $margin - 14, $detailBaseline + 4);
        $this->drawText(
            $image,
            $coordinateText,
            $detailSize,
            $contentLeft,
            $coordinateBaseline,
            $softWhite,
            $fontRegular,
        );
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
            throw new InvalidArgumentException('Gunakan foto berformat JPG, PNG, atau WebP.');
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

    private function writeCompressedJpeg(GdImage $image, string $path): void
    {
        $targetSize = max(300 * 1024, (int) config('foto_barang.target_file_size', 1400 * 1024));

        foreach ([88, 84, 80, 76, 72, 68] as $quality) {
            if (! imagejpeg($image, $path, $quality)) {
                throw new RuntimeException('Gagal mengompres foto.');
            }

            clearstatcache(true, $path);

            if (filesize($path) <= $targetSize) {
                return;
            }
        }
    }

    private function fontPath(bool $bold): ?string
    {
        $windows = getenv('WINDIR') ?: 'C:\\Windows';
        $candidates = $bold
            ? [
                resource_path('fonts/RobotoCondensed-Bold.ttf'),
                resource_path('fonts/DejaVuSans-Bold.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/opentype/noto/NotoSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
                $windows.'\\Fonts\\arialbd.ttf',
            ]
            : [
                resource_path('fonts/RobotoCondensed-Regular.ttf'),
                resource_path('fonts/DejaVuSans.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/opentype/noto/NotoSans-Regular.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
                $windows.'\\Fonts\\arial.ttf',
            ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function drawText(
        GdImage $image,
        string $text,
        int $size,
        int $x,
        int $baseline,
        int $color,
        ?string $font,
    ): void {
        if ($font !== null && function_exists('imagettftext')) {
            imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);

            return;
        }

        $ascii = $this->ascii($text);
        $sourceWidth = max(1, imagefontwidth(5) * strlen($ascii));
        $sourceHeight = imagefontheight(5);
        $scale = max(1, $size / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $textLayer = imagecreatetruecolor($sourceWidth, $sourceHeight);
        imagealphablending($textLayer, false);
        imagesavealpha($textLayer, true);
        $transparent = imagecolorallocatealpha($textLayer, 0, 0, 0, 127);
        imagefill($textLayer, 0, 0, $transparent);
        $components = imagecolorsforindex($image, $color);
        $layerColor = imagecolorallocatealpha(
            $textLayer,
            $components['red'],
            $components['green'],
            $components['blue'],
            $components['alpha'],
        );
        imagestring($textLayer, 5, 0, 0, $ascii, $layerColor);
        imagealphablending($image, true);
        imagecopyresampled(
            $image,
            $textLayer,
            $x,
            max(0, $baseline - $targetHeight),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );
        imagedestroy($textLayer);
    }

    private function textWidth(string $text, int $size, ?string $font): int
    {
        if ($font !== null && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $font, $text);

            if (is_array($box)) {
                return (int) abs($box[2] - $box[0]);
            }
        }

        $scale = max(1, $size / imagefontheight(5));

        return (int) round(imagefontwidth(5) * strlen($this->ascii($text)) * $scale);
    }

    /** @return array<int, string> */
    private function wrapText(string $text, int $size, ?string $font, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current.' '.$word);

            if ($current !== '' && $this->textWidth($candidate, $size, $font) > $maxWidth) {
                $lines[] = $current;
                $current = $word;

                if (count($lines) === $maxLines - 1) {
                    break;
                }

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $consumed = implode(' ', $lines);
            $remaining = trim(substr($text, strlen($consumed)));
            $lines[] = $this->fitText($remaining ?: $current, $size, $font, $maxWidth);
        }

        return $lines;
    }

    private function fitText(string $text, int $size, ?string $font, int $maxWidth): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($this->textWidth($text, $size, $font) <= $maxWidth) {
            return $text;
        }

        while (mb_strlen($text) > 3 && $this->textWidth($text.'...', $size, $font) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'...';
    }

    private function ascii(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii === false ? '' : $ascii;
    }
}
