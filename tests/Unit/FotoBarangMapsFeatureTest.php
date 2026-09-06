<?php

namespace Tests\Unit;

use App\Filament\Pages\FotoBarangFolder;
use App\Http\Controllers\FotoBarangMediaController;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Services\FotoBarangImageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class FotoBarangMapsFeatureTest extends TestCase
{
    public function test_gallery_thumbnail_is_lightweight_and_bounded(): void
    {
        Storage::fake('local');
        $source = imagecreatetruecolor(1200, 1600);
        $background = imagecolorallocate($source, 220, 120, 40);
        imagefill($source, 0, 0, $background);
        ob_start();
        imagejpeg($source, null, 92);
        $contents = (string) ob_get_clean();
        imagedestroy($source);
        Storage::disk('local')->put('foto-barang/test/source.jpg', $contents);

        $session = new FotoBarangSession(['uuid' => 'test']);
        $photo = new FotoBarangItem(['path' => 'foto-barang/test/source.jpg']);
        $photo->setAttribute('id', 10);
        $method = new ReflectionMethod(FotoBarangMediaController::class, 'ensureThumbnail');
        $method->setAccessible(true);
        $thumbnailPath = $method->invoke(new FotoBarangMediaController, $session, $photo);
        $thumbnail = getimagesize(Storage::disk('local')->path($thumbnailPath));

        $this->assertNotFalse($thumbnail);
        $this->assertLessThanOrEqual(480, max($thumbnail[0], $thumbnail[1]));
        $this->assertLessThan(strlen($contents), Storage::disk('local')->size($thumbnailPath));
    }

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
            $this->assertSame(1456, $result['height']);
            $this->assertLessThanOrEqual(1400 * 1024, $result['file_size']);

            $rendered = imagecreatefromjpeg($result['path']);
            $topPixel = imagecolorsforindex($rendered, imagecolorat($rendered, 30, 30));
            $bottomPixel = imagecolorsforindex($rendered, imagecolorat($rendered, 100, 1370));

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
        $captureIdMigration = (string) file_get_contents(
            $root.'/database/migrations/2026_09_02_020000_add_client_capture_id_to_foto_barang_items.php',
        );
        $page = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangMaps.php');
        $mainView = (string) file_get_contents($root.'/resources/views/filament/pages/foto-barang-maps.blade.php');
        $cameraScript = (string) file_get_contents($root.'/resources/js/foto-barang-maps.js');
        $folderPage = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangFolder.php');
        $folderView = (string) file_get_contents($root.'/resources/views/filament/pages/foto-barang-folder.blade.php');
        $folderScript = (string) file_get_contents($root.'/resources/js/foto-barang-folder.js');
        $view = implode("\n", [$mainView, $cameraScript, $folderView, $folderScript]);
        $job = (string) file_get_contents($root.'/app/Jobs/ProcessFotoBarangImage.php');
        $imageService = (string) file_get_contents($root.'/app/Services/FotoBarangImageService.php');
        $deletionService = (string) file_get_contents($root.'/app/Services/FotoBarangDeletionService.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/FotoBarangMediaController.php');
        $routes = (string) file_get_contents($root.'/routes/web.php');

        $this->assertStringContainsString("Schema::create('foto_barang_sessions'", $migration);
        $this->assertStringContainsString("Schema::create('foto_barang_items'", $migration);
        $this->assertStringNotContainsString("Schema::table('barangs'", $migration);
        $this->assertStringNotContainsString("Schema::table('barang_lokasi'", $migration);
        $this->assertStringNotContainsString("Schema::table('mutasis'", $migration);
        $this->assertStringContainsString("Schema::table('foto_barang_items'", $processingMigration);
        $this->assertStringContainsString("->default('completed')", $processingMigration);
        $this->assertStringNotContainsString("Schema::dropIfExists('foto_barang_items')", $processingMigration);
        $this->assertStringContainsString("'client_capture_id'", $captureIdMigration);
        $this->assertStringContainsString('foto_barang_session_client_capture_unique', $captureIdMigration);
        $this->assertStringContainsString('public function startSession', $page);
        $this->assertStringContainsString('public function finishSession', $page);
        $this->assertStringContainsString('public function savePhoto', $page);
        $this->assertStringContainsString('public function deleteSelectedPhotos', $page);
        $this->assertStringContainsString('foto_barang_bulk_delete', $page);
        $this->assertStringContainsString('filled($this->capturedAt) || filled($this->clientCaptureId)', $page);
        $this->assertStringContainsString('public function updateCaptureMetadata', $page);
        $this->assertStringContainsString('public function resolveSessionLocation', $page);
        $this->assertStringContainsString('public function applyHandayaniTemplateLocation', $page);
        $this->assertStringContainsString('$this->skipRender()', $page);
        $this->assertStringNotContainsString('capture="environment"', $view);
        $this->assertStringNotContainsString('Kamera / Galeri Alternatif', $view);
        $this->assertStringNotContainsString('useDefaultGps', $view);
        $this->assertStringNotContainsString('useDefaultLocation', $page);
        $this->assertStringNotContainsString('window.confirm', $view);
        $this->assertStringNotContainsString('wire:confirm', $view);
        $this->assertStringNotContainsString('target="_blank"', $view);
        $this->assertStringContainsString('navigator.mediaDevices.getUserMedia', $view);
        $this->assertStringContainsString('waitForCameraReady', $view);
        $this->assertStringContainsString('cameraReady', $view);
        $this->assertStringContainsString('playShutterBeep()', $view);
        $this->assertStringContainsString('window.AudioContext || window.webkitAudioContext', $view);
        $this->assertStringContainsString('this.playShutterBeep();', $view);
        $this->assertStringContainsString('toggleShutterBeep()', $view);
        $this->assertStringContainsString("localStorage.getItem('handayani-foto-maps-beep')", $view);
        $this->assertStringContainsString("beepEnabled ? 'Beep Aktif' : 'Beep Nonaktif'", $view);
        $this->assertStringContainsString('async toggleTorch()', $view);
        $this->assertStringContainsString('getCapabilities?.()', $view);
        $this->assertStringContainsString('advanced: [{ torch: enable }]', $view);
        $this->assertStringContainsString("torchEnabled ? 'Flash Aktif' : 'Flash Nonaktif'", $view);
        $this->assertStringContainsString('x-on:click="closeCameraAndRefresh()"', $view);
        $this->assertStringContainsString('Keluar Kamera', $view);
        $this->assertStringNotContainsString('finishCaptureSession()', $view);
        $this->assertStringContainsString('closeCameraAndRefresh', $view);
        $this->assertStringContainsString('refreshInProgress', $view);
        $this->assertStringContainsString('x-bind:disabled="refreshInProgress"', $view);
        $this->assertStringContainsString('scheduleServerRefresh', $view);
        $this->assertStringContainsString("indexedDB.open('handayani-foto-maps'", $view);
        $this->assertStringContainsString("captureMode: 'server'", $view);
        $this->assertStringContainsString("captureMode === 'local'", $view);
        $this->assertStringContainsString('mode: this.captureMode', $view);
        $this->assertStringContainsString("readLocalCaptures(sessionUuid, mode = 'server')", $view);
        $this->assertStringContainsString("this.readLocalCaptures(sessionUuid, 'server')", $view);
        $this->assertStringContainsString("this.readLocalCaptures(sessionUuid, 'local')", $view);
        $this->assertStringContainsString('persistSessionRecovery(cameraWasOpen = this.cameraOpen)', $view);
        $this->assertStringContainsString("localStorage.getItem('handayani-foto-maps-recovery')", $view);
        $this->assertStringContainsString('resumeRecoveredSession()', $view);
        $this->assertStringContainsString('Sesi sebelumnya dipulihkan', $view);
        $this->assertStringContainsString('drawLocalWatermark(canvas, context, capturedAt)', $view);
        $this->assertStringContainsString('downloadLocalCapture(captureId)', $view);
        $this->assertStringContainsString('shareLocalCapture(captureId)', $view);
        $this->assertStringContainsString('deleteLocalOnlyCapture(captureId)', $view);
        $this->assertStringContainsString('Mode Lokal HP', $view);
        $this->assertStringContainsString('Alamat otomatis dari GPS', $view);
        $this->assertStringNotContainsString('wire:model="namaLokasi"', $view);
        $this->assertStringNotContainsString('wire:model="alamat"', $view);
        $this->assertStringContainsString('saveLocalCapture(capture)', $view);
        $this->assertStringContainsString('processUploadQueue()', $view);
        $this->assertStringContainsString('Foto tetap aman di perangkat', $view);
        $this->assertStringContainsString('x-ref="cameraVideo"', $view);
        $this->assertStringContainsString('wire:ignore', $view);
        $this->assertStringContainsString("method: 'POST'", $view);
        $this->assertStringContainsString("'X-CSRF-TOKEN'", $view);
        $this->assertStringContainsString("route('foto-barang.upload'", $view);
        $this->assertStringNotContainsString('$wire.upload(', $view);
        $this->assertStringNotContainsString('$wire.updateCaptureMetadata(', $view);
        $this->assertStringContainsString('async refreshGps(force = true)', $view);
        $this->assertStringContainsString('useHandayaniTemplateLocation()', $view);
        $this->assertStringContainsString("this.locationMode === 'template'", $view);
        $this->assertStringContainsString('Template Handayani', $view);
        $this->assertStringContainsString('await this.refreshGps(false)', $view);
        $this->assertStringContainsString("config('foto_barang.handayani_location.latitude'", $page);
        $this->assertStringContainsString("'foto_barang.handayani_location.address'", $page);

        preg_match(
            '/async useHandayaniTemplateLocation\(\) \{(.*?)\n\s*\},\n\s*async refreshGps/s',
            $view,
            $templateLocationMethod,
        );
        $this->assertArrayHasKey(1, $templateLocationMethod);
        $this->assertStringNotContainsString('navigator.geolocation', $templateLocationMethod[1]);
        $this->assertStringContainsString('Unduh Semua ZIP', $view);
        $this->assertStringContainsString('sharePhoto(', $view);
        $this->assertStringContainsString('shareAllSessionPhotos(', $view);
        $this->assertStringContainsString('shareSessionArchive(', $view);
        $this->assertStringContainsString('navigator.canShare({ files })', $view);
        $this->assertStringContainsString('Bagikan Semua ke WhatsApp', $view);
        $this->assertStringContainsString('openServerGallery', $view);
        $this->assertStringContainsString('fm-image-skeleton', $view);
        $this->assertStringContainsString("route('foto-barang.thumbnail'", $view);
        $this->assertStringContainsString('handlePhotoClick({{ $photo->id }}, {{ $loop->index }})', $folderView);
        $this->assertStringContainsString('startLongPress', $folderScript);
        $this->assertStringContainsString('selectedIds', $folderScript);
        $this->assertStringContainsString('Pilih halaman ini', $folderView);
        $this->assertStringContainsString('Unduh Terpilih', $view);
        $this->assertStringContainsString('Hapus', $folderView);
        $this->assertStringContainsString('this.$wire.deleteSelectedPhotos', $folderScript);
        $this->assertStringContainsString("route('foto-barang.selected-archive'", $view);
        $this->assertStringNotContainsString('x-data="{ imageReady:', $view);
        $this->assertStringNotContainsString('x-on:pointerdown.passive', $view);
        $this->assertStringContainsString('syncServerPhotosFromDom', $view);
        $this->assertStringContainsString('x-ref="viewerDialog"', $folderView);
        $this->assertStringContainsString('serverRefreshPending', $view);
        $this->assertStringContainsString('dialog.dataset.deleteType', $view);
        $this->assertStringContainsString('x-ref="confirmTextInput"', $view);
        $this->assertStringNotContainsString('confirmBusy || (confirmRequiresText', $view);
        $this->assertStringContainsString('showNextServerPhoto', $view);
        $this->assertStringContainsString('endGallerySwipe', $view);
        $this->assertStringContainsString('requestDeleteFolder', $view);
        $this->assertStringContainsString('dialog.showModal()', $view);
        $this->assertStringContainsString('x-ref="confirmDialog"', $view);
        $this->assertStringNotContainsString("this.confirmOpen = true;\n                document.body.style.overflow = 'hidden';", $view);
        $this->assertStringContainsString("confirmation.toLowerCase() !== 'hapus'", $view);
        $this->assertStringContainsString('wire:model.live="historyDate"', $view);
        $this->assertStringContainsString("paginate(10, ['*'], 'fotoSessionsPage')", $page);
        $this->assertStringContainsString("paginate(12, ['*'], 'photosPage')", $folderPage);
        $this->assertStringNotContainsString("->with(['items'", $page);
        $this->assertStringContainsString('public function shareManifest', $folderPage);
        $this->assertStringContainsString("manifest?.mode !== 'direct'", $folderScript);
        $this->assertStringContainsString(
            '/admin/foto-barang-maps/folder/example-session',
            FotoBarangFolder::getUrl(['session' => 'example-session']),
        );
        $this->assertStringContainsString('public function deleteSessionFolder', $page);
        $this->assertStringContainsString("return ['deleted' => true, 'photo_id' => \$photoId]", $page);
        $this->assertStringContainsString("!== 'hapus'", $page);
        $this->assertStringContainsString('ShouldBeUnique', $job);
        $this->assertStringContainsString('PROCESSING_FAILED', $job);
        $this->assertStringContainsString('public function stage(', $imageService);
        $this->assertStringContainsString('validateProcessedFile', $imageService);
        $this->assertStringContainsString('foto sumber tetap dipertahankan', $imageService);
        $this->assertStringContainsString('public function deleteMany', $deletionService);
        $this->assertStringContainsString("'foto-barang-trash/'", $deletionService);
        $this->assertStringContainsString('$disk->move($path, $trashPath)', $deletionService);
        $this->assertStringContainsString('$disk->move($trashPath, $originalPath)', $deletionService);
        $this->assertStringContainsString('DB::transaction', $deletionService);
        $this->assertStringContainsString('public function archiveSelected', $controller);
        $this->assertStringContainsString("->name('foto-barang.selected-archive')", $routes);

        $this->assertStringContainsString("x-data='fotoBarangMaps({", $mainView);
        $this->assertStringContainsString("@vite('resources/js/foto-barang-maps.js')", $mainView);
        $this->assertStringContainsString("@vite('resources/js/foto-barang-folder.js')", $folderView);
        $this->assertStringNotContainsString('navigator.mediaDevices.getUserMedia', $mainView);

        $this->assertFileExists($root.'/resources/fonts/RobotoCondensed-Regular.ttf');
        $this->assertFileExists($root.'/resources/fonts/RobotoCondensed-Bold.ttf');
    }

    public function test_private_media_routes_require_authentication(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        $this->assertStringContainsString("Route::middleware('auth')->prefix('foto-barang-media')", $routes);
        $this->assertStringContainsString("->name('foto-barang.upload')", $routes);
        $this->assertStringContainsString("->name('foto-barang.preview')", $routes);
        $this->assertStringContainsString("->name('foto-barang.thumbnail')", $routes);
        $this->assertStringContainsString("->name('foto-barang.archive')", $routes);
        $this->assertStringContainsString("->name('foto-barang.selected-archive')", $routes);
    }
}
