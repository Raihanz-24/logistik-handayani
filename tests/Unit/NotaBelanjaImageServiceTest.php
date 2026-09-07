<?php

namespace Tests\Unit;

use App\Services\NotaBelanjaImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotaBelanjaImageServiceTest extends TestCase
{
    public function test_foto_nota_dikompres_dan_disimpan_sebagai_webp(): void
    {
        Storage::fake('public');

        $upload = UploadedFile::fake()->image('nota.jpg', 2400, 1800)->size(8000);
        $path = app(NotaBelanjaImageService::class)->store($upload);

        Storage::disk('public')->assertExists($path);

        $storedPath = Storage::disk('public')->path($path);
        [$width, $height] = getimagesize($storedPath);

        $this->assertStringStartsWith('nota-belanja/', $path);
        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame('image/webp', mime_content_type($storedPath));
        $this->assertLessThanOrEqual(1800, max($width, $height));
        $this->assertLessThan(900 * 1024, filesize($storedPath));
    }
}
