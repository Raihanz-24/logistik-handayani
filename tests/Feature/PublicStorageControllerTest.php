<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageControllerTest extends TestCase
{
    public function test_it_serves_an_existing_public_file_without_a_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('barang/contoh.webp', 'webp-content');

        $response = $this->get('/media/barang/contoh.webp');

        $response
            ->assertOk()
            ->assertHeader('cache-control', 'immutable, max-age=31536000, public')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->assertSame('webp-content', $response->streamedContent());
    }

    public function test_it_returns_not_found_for_a_missing_public_file(): void
    {
        Storage::fake('public');

        $this->get('/media/barang/tidak-ada.webp')->assertNotFound();
    }
}
