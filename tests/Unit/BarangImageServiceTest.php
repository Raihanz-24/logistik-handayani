<?php

namespace Tests\Unit;

use App\Services\BarangImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BarangImageServiceTest extends TestCase
{
    public function test_it_resizes_and_converts_an_uploaded_image_to_webp(): void
    {
        Storage::fake('public');

        $upload = UploadedFile::fake()->image('barang-besar.jpg', 2400, 1800)->size(8000);
        $path = app(BarangImageService::class)->store($upload);

        Storage::disk('public')->assertExists($path);

        $storedPath = Storage::disk('public')->path($path);
        [$width, $height] = getimagesize($storedPath);

        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame('image/webp', mime_content_type($storedPath));
        $this->assertLessThanOrEqual(1600, max($width, $height));
        $this->assertLessThan(700 * 1024, filesize($storedPath));
    }

    public function test_barang_form_accepts_a_large_original_for_automatic_compression(): void
    {
        $resource = (string) file_get_contents(app_path('Filament/Resources/BarangResource.php'));

        $this->assertStringContainsString('MAX_ORIGINAL_IMAGE_SIZE_KB = 10 * 1024', $resource);
        $this->assertStringContainsString('Kompresi gambar otomatis aktif', $resource);
        $this->assertStringContainsString('Gambar berhasil dikompres', $resource);
        $this->assertStringNotContainsString('->maxSize(3072)', $resource);
    }
}
