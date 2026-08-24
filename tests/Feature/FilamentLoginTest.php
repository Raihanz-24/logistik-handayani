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

class FilamentLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_login_page_uses_the_custom_design(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Selamat datang kembali')
            ->assertSee('Ingat saya di perangkat ini')
            ->assertSee('Operasional gudang dalam satu kendali.');
    }

    public function test_user_can_log_in_and_be_remembered(): void
    {
        Role::create([
            'name' => 'user',
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secure-password'),
        ]);

        Livewire::test(Login::class)
            ->set('data.username', $user->username)
            ->set('data.password', 'secure-password')
            ->set('data.remember', true)
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->getRememberToken());
    }
}
