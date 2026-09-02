<?php

namespace Tests\Unit;

use App\Models\FotoBarangSession;
use App\Services\FotoBarangImageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use ReflectionMethod;
use Tests\TestCase;

class FotoBarangMapsFeatureTest extends TestCase
{
    public function test_photo_is_resized_watermarked_and_compressed_as_jpeg(): void
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'foto-maps-source-');
        $source = imagecreatetruecolor(1200, 1600);
        $white = imagecolorallocate($source, 245, 245, 245);
        imagefill($source, 0, 0, $white);
        imagejpeg($source, $sourcePath, 95);
        imagedestroy($source);

        $upload = new UploadedFile($sourcePath, 'barang-datang.jpg', 'image/jpeg', null, true);
        $session = new FotoBarangSession([
            'nama_lokasi' => 'Kecamatan Paiton, Jawa Timur, Indonesia',
            'alamat' => 'Jl. Raya Paiton No. km.137, Kabupaten Probolinggo, Jawa Timur 67291, Indonesia',
        ]);
        $method = new ReflectionMethod(FotoBarangImageService::class, 'render');
        $method->setAccessible(true);

        /** @var array{path: string, file_size: int, width: int, height: int} $result */
        $result = $method->invoke(
            new FotoBarangImageService,
            $upload,
            $session,
            -7.717710,
            113.537297,
            12,
            CarbonImmutable::parse('2026-09-02 10:02:00', 'Asia/Jakarta'),
        );

        try {
            $this->assertSame('image/jpeg', mime_content_type($result['path']));
            $this->assertSame(1200, $result['width']);
            $this->assertSame(1600, $result['height']);
            $this->assertLessThanOrEqual(1400 * 1024, $result['file_size']);

            $rendered = imagecreatefromjpeg($result['path']);
            $topPixel = imagecolorsforindex($rendered, imagecolorat($rendered, 30, 30));
            $bottomPixel = imagecolorsforindex($rendered, imagecolorat($rendered, 100, 1500));

            $this->assertGreaterThan(220, $topPixel['red']);
            $this->assertLessThan(170, $bottomPixel['red']);

            imagedestroy($rendered);
        } finally {
            @unlink($result['path']);
            @unlink($sourcePath);
        }
    }

    public function test_feature_uses_new_tables_and_does_not_touch_stock_data(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents(
            $root.'/database/migrations/2026_09_02_000000_create_foto_barang_tables.php',
        );
        $processingMigration = (string) file_get_contents(
            $root.'/database/migrations/2026_09_02_010000_add_processing_status_to_foto_barang_items.php',
        );
        $page = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangMaps.php');
        $view = (string) file_get_contents($root.'/resources/views/filament/pages/foto-barang-maps.blade.php');
        $job = (string) file_get_contents($root.'/app/Jobs/ProcessFotoBarangImage.php');
        $imageService = (string) file_get_contents($root.'/app/Services/FotoBarangImageService.php');

        $this->assertStringContainsString("Schema::create('foto_barang_sessions'", $migration);
        $this->assertStringContainsString("Schema::create('foto_barang_items'", $migration);
        $this->assertStringNotContainsString("Schema::table('barangs'", $migration);
        $this->assertStringNotContainsString("Schema::table('barang_lokasi'", $migration);
        $this->assertStringNotContainsString("Schema::table('mutasis'", $migration);
        $this->assertStringContainsString("Schema::table('foto_barang_items'", $processingMigration);
        $this->assertStringContainsString("->default('completed')", $processingMigration);
        $this->assertStringNotContainsString("Schema::dropIfExists('foto_barang_items')", $processingMigration);
        $this->assertStringContainsString('public function startSession', $page);
        $this->assertStringContainsString('public function finishSession', $page);
        $this->assertStringContainsString('public function savePhoto', $page);
        $this->assertStringContainsString('public function updateCaptureMetadata', $page);
        $this->assertStringContainsString('$this->skipRender()', $page);
        $this->assertStringContainsString('capture="environment"', $view);
        $this->assertStringContainsString('navigator.mediaDevices.getUserMedia', $view);
        $this->assertStringContainsString('waitForCameraReady', $view);
        $this->assertStringContainsString('cameraReady', $view);
        $this->assertStringContainsString('closeCameraAndRefresh', $view);
        $this->assertStringContainsString('Mengamankan foto sumber', $view);
        $this->assertStringContainsString('kompresi dilanjutkan di latar belakang', $view);
        $this->assertStringContainsString('x-ref="cameraVideo"', $view);
        $this->assertStringContainsString('wire:ignore', $view);
        $this->assertStringContainsString('$wire.upload(', $view);
        $this->assertStringContainsString('refreshGps()', $view);
        $this->assertStringContainsString('Unduh Semua ZIP', $view);
        $this->assertStringContainsString('sharePhoto(', $view);
        $this->assertStringContainsString('ShouldBeUnique', $job);
        $this->assertStringContainsString('PROCESSING_FAILED', $job);
        $this->assertStringContainsString('public function stage(', $imageService);
        $this->assertStringContainsString('validateProcessedFile', $imageService);
        $this->assertStringContainsString('foto sumber tetap dipertahankan', $imageService);

        $this->assertFileExists($root.'/resources/fonts/RobotoCondensed-Regular.ttf');
        $this->assertFileExists($root.'/resources/fonts/RobotoCondensed-Bold.ttf');
    }

    public function test_private_media_routes_require_authentication(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        $this->assertStringContainsString("Route::middleware('auth')->prefix('foto-barang-media')", $routes);
        $this->assertStringContainsString("->name('foto-barang.preview')", $routes);
        $this->assertStringContainsString("->name('foto-barang.archive')", $routes);
    }
}
