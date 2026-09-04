<?php

namespace Tests\Unit;

use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Services\FotoBarangImageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoBarangEditorTest extends TestCase
{
    public function test_time_revision_creates_a_new_jpeg_without_changing_the_original(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(1200, 1456);
        $background = imagecolorallocate($image, 120, 150, 180);
        imagefill($image, 0, 0, $background);
        $overlay = imagecolorallocate($image, 5, 10, 18);
        imagefilledrectangle($image, 30, 1030, 1170, 1420, $overlay);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        $path = 'foto-barang/test/001-processed.jpg';
        Storage::disk('local')->put($path, $contents);
        $originalHash = sha1_file(Storage::disk('local')->path($path));
        $session = new FotoBarangSession([
            'uuid' => 'test',
            'nama_lokasi' => 'Kecamatan Paiton, Jawa Timur, Indonesia',
            'alamat' => 'Jl. Raya Paiton No. KM 137, Dusun Matikan, Sumberejo, Kec. Paiton, Kabupaten Probolinggo, Jawa Timur 67291, Indonesia',
        ]);
        $photo = new FotoBarangItem([
            'path' => $path,
            'processing_status' => FotoBarangItem::PROCESSING_COMPLETED,
            'urutan' => 1,
        ]);
        $photo->setRelation('session', $session);

        $result = (new FotoBarangImageService)->renderTimeRevision(
            $photo,
            CarbonImmutable::parse('2026-09-01 10:02:00', 'Asia/Jakarta'),
        );

        try {
            $this->assertFileExists($result['path']);
            $this->assertSame('image/jpeg', mime_content_type($result['path']));
            $this->assertSame(1200, $result['width']);
            $this->assertSame(1456, $result['height']);
            $this->assertSame($originalHash, sha1_file(Storage::disk('local')->path($path)));
            $this->assertNotSame($originalHash, sha1_file($result['path']));
        } finally {
            if (is_file($result['path'])) {
                unlink($result['path']);
            }
        }
    }

    public function test_editor_is_isolated_from_original_photos_and_stock_tables(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents(
            $root.'/database/migrations/2026_09_04_000000_create_foto_barang_edits_table.php',
        );
        $page = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangEditor.php');
        $view = (string) file_get_contents($root.'/resources/views/filament/pages/foto-barang-editor.blade.php');
        $service = (string) file_get_contents($root.'/app/Services/FotoBarangEditService.php');
        $imageService = (string) file_get_contents($root.'/app/Services/FotoBarangImageService.php');
        $routes = (string) file_get_contents($root.'/routes/web.php');

        $this->assertStringContainsString("Schema::create('foto_barang_edits'", $migration);
        $this->assertStringNotContainsString("Schema::table('foto_barang_items'", $migration);
        $this->assertStringNotContainsString("Schema::table('barang_lokasi'", $migration);
        $this->assertStringNotContainsString("Schema::table('mutasis'", $migration);
        $this->assertStringContainsString("protected static ?string \$navigationLabel = 'Editor Foto Maps'", $page);
        $this->assertStringContainsString('public function createEditedPhoto', $page);
        $this->assertStringContainsString('Foto asli tetap tersimpan', $page);
        $this->assertStringContainsString('Folder terpisah', $view);
        $this->assertStringContainsString('Hasil Edit', $view);
        $this->assertStringContainsString('Buat Hasil Edit', $view);
        $this->assertStringContainsString(".'/hasil-edit/'.sprintf(", $service);
        $this->assertStringContainsString('renderTimeRevision', $imageService);
        $this->assertStringNotContainsString("'Diedit'", $imageService);
        $this->assertStringContainsString("->name('foto-barang.edit-preview')", $routes);
        $this->assertStringContainsString("->name('foto-barang.edit-download')", $routes);
    }
}
