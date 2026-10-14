<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Memastikan halaman Asisten AI benar-benar dapat dirender (HTTP) dengan
 * tata letak chat penuh, tanpa error Blade/Livewire.
 */
class AsistenAiPageHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_chat_dapat_dirender_penuh(): void
    {
        config(['ai.enabled' => true, 'ai.api_key' => 'x', 'ai.base_url' => 'https://x.test/v1']);

        $user = User::factory()->create();
        Permission::findOrCreate('view_any_barang', 'web');
        $user->givePermissionTo('view_any_barang');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh());

        $response = $this->get('/admin/asisten-ai');

        $response->assertOk();
        $response->assertSee('ai-page-wrap', false);
        $response->assertSee('ai-chat__bar', false);
        $response->assertSee('ai-history__panel', false);

        // Pastikan modul JS Asisten sebenarnya dimuat di halaman (tahan SPA).
        $response->assertSee('ai-assistant', false);
    }
}
