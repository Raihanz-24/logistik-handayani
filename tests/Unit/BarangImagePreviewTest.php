<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BarangImagePreviewTest extends TestCase
{
    public function test_master_and_stock_tables_open_images_in_a_preview_modal(): void
    {
        $root = dirname(__DIR__, 2);
        $barangResource = (string) file_get_contents($root.'/app/Filament/Resources/BarangResource.php');
        $stockResource = (string) file_get_contents($root.'/app/Filament/Resources/BarangLokasiResource.php');
        $preview = (string) file_get_contents(
            $root.'/resources/views/filament/components/barang-image-preview.blade.php',
        );

        $this->assertStringContainsString("ImageColumn::make('gambar')", $barangResource);
        $this->assertStringContainsString("TableAction::make('preview-gambar-barang')", $barangResource);
        $this->assertStringContainsString("ImageColumn::make('barang.gambar')", $stockResource);
        $this->assertStringContainsString("Action::make('preview-gambar-stok')", $stockResource);
        $this->assertStringContainsString('protected function resolveTableRecord', (string) file_get_contents(
            $root.'/app/Filament/Resources/BarangLokasiResource/Pages/ListBarangLokasis.php',
        ));
        $this->assertStringContainsString("->modalCancelActionLabel('Kembali')", $barangResource);
        $this->assertStringContainsString("->modalCancelActionLabel('Kembali')", $stockResource);
        $this->assertStringContainsString("Storage::disk('public')->url", $preview);
        $this->assertStringContainsString('object-fit: contain', $preview);
    }
}
