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

        $upload = UploadedFile::fake()->image('barang-besar.jpg', 2400, 1800)->size(3000);
        $path = app(BarangImageService::class)->store($upload);

        Storage::disk('public')->assertExists($path);

        $storedPath = Storage::disk('public')->path($path);
        [$width, $height] = getimagesize($storedPath);

        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame('image/webp', mime_content_type($storedPath));
        $this->assertLessThanOrEqual(1600, max($width, $height));
        $this->assertLessThan(700 * 1024, filesize($storedPath));
    }
}
