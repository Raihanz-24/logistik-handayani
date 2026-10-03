<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SSO Callback (Logistik sebagai client) — /sso/callback.
 *
 * Memverifikasi:
 * - state session one-time (anti-CSRF/replay);
 * - tukar code server-to-server + resolusi portal_uuid → user;
 * - TIDAK ADA auto-create untuk UUID tak dikenal;
 * - status != active ditolak.
 */
class SsoCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sso.enabled', true);
        config()->set('sso.portal_base_url', 'https://portal.handayani.my.id');
        config()->set('sso.client_id', 'app_test_client');
        config()->set('sso.client_secret', 'secret_test');
        config()->set('sso.redirect_uri', 'https://handayani.my.id/sso/callback');

        Role::create(['name' => 'user', 'guard_name' => 'web']);
    }

    private function startFlow(): string
    {
        $this->get('/sso/login')->assertRedirect();

        return (string) session('sso.state');
    }

    private function fakeToken(string $uuid, string $status = 'active'): void
    {
        Http::fake([
            'portal.handayani.my.id/oauth/token' => Http::response([
                'portal_uuid' => $uuid,
                'email' => 'someone@example.com',
                'status' => $status,
            ], 200),
        ]);
    }

    public function test_successful_callback_logs_user_in(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $state = $this->startFlow();
        $this->fakeToken($user->portal_uuid);

        $response = $this->get('/sso/callback?code=abc123&state='.$state);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // State sekali pakai: sudah dihapus.
        $this->assertNull(session('sso.state'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/token')
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'abc123'
            && $request['client_secret'] === 'secret_test');
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $this->startFlow();
        $this->fakeToken($user->portal_uuid);

        $response = $this->get('/sso/callback?code=abc123&state=wrong-state');

        $response->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_rejects_replayed_state(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $state = $this->startFlow();
        $this->fakeToken($user->portal_uuid);

        // Pemakaian pertama sukses.
        $this->get('/sso/callback?code=abc123&state='.$state)->assertRedirect();

        // Pemakaian kedua (state sudah dihapus) harus ditolak.
        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_callback_rejects_expired_state(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        config()->set('sso.state_ttl', 300);

        $state = $this->startFlow();
        $this->fakeToken($user->portal_uuid);

        // Simulasikan flow dimulai > TTL detik lalu.
        session()->put('sso.started_at', time() - 301);

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
        Http::assertNothingSent();

        // State sudah dikonsumsi (sekali pakai) walau kedaluwarsa.
        $this->assertNull(session('sso.state'));
    }

    public function test_callback_rejects_unknown_portal_uuid_without_auto_create(): void
    {
        // Tidak ada user dengan portal_uuid ini.
        $unknown = (string) Str::uuid();

        $state = $this->startFlow();
        $this->fakeToken($unknown);

        $countBefore = User::query()->count();

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
        $this->assertSame($countBefore, User::query()->count(), 'Tidak boleh auto-create user.');
        $this->assertNull(User::query()->where('portal_uuid', $unknown)->first());
    }

    public function test_callback_rejects_inactive_status(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $state = $this->startFlow();
        $this->fakeToken($user->portal_uuid, 'inactive');

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_callback_rejects_when_token_exchange_fails(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $state = $this->startFlow();

        Http::fake([
            'portal.handayani.my.id/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_callback_404_when_sso_disabled(): void
    {
        config()->set('sso.enabled', false);

        $this->get('/sso/callback?code=x&state=y')->assertNotFound();
    }
}
