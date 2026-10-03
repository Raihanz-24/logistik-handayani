<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SSO GATE pada halaman login Logistik.
 *
 * - SSO aktif (SSO_ENABLED=true)  → halaman login Logistik DITUTUP overlay
 *   (pop-up terkunci) dengan tombol menuju Portal.
 * - SSO nonaktif                  → login langsung tetap terbuka (tanpa overlay).
 */
class SsoLoginGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_login_page_is_gated_when_sso_enabled(): void
    {
        config()->set('sso.enabled', true);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('wm-sso-gate', false)
            ->assertSee('Masuk melalui Portal Handayani')
            ->assertSee('Login dengan Portal Handayani')
            // Tombol mengarah ke route SSO login.
            ->assertSee(route('sso.login'), false);
    }

    public function test_login_page_is_open_when_sso_disabled(): void
    {
        config()->set('sso.enabled', false);

        $this->get('/admin/login')
            ->assertOk()
            // Overlay tidak dirender.
            ->assertDontSee('wm-sso-gate')
            ->assertDontSee('Masuk melalui Portal Handayani')
            // Form login normal tetap ada.
            ->assertSee('Masuk ke dashboard');
    }

    // -----------------------------------------------------------------
    // Penjagaan server-side: login langsung ditolak saat SSO aktif.
    // -----------------------------------------------------------------

    public function test_direct_login_is_rejected_server_side_when_sso_enabled(): void
    {
        config()->set('sso.enabled', true);

        $user = User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secure-password'),
        ]);

        Livewire::test(Login::class)
            ->set('data.username', $user->username)
            ->set('data.password', 'secure-password')
            ->call('authenticate');

        // Tidak boleh terautentikasi walau kredensial benar.
        $this->assertGuest();
    }

    public function test_direct_login_works_when_sso_disabled(): void
    {
        config()->set('sso.enabled', false);

        Role::findOrCreate('user', 'web');

        $user = User::factory()->create([
            'username' => 'admin2',
            'email' => 'admin2@example.com',
            'password' => Hash::make('secure-password'),
        ]);
        $user->assignRole('user');

        Livewire::test(Login::class)
            ->set('data.username', $user->username)
            ->set('data.password', 'secure-password')
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }
}
