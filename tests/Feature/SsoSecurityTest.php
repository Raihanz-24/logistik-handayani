<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PHASE 6 — Integration & SECURITY testing sisi Logistik (client).
 *
 * Fokus: ketahanan callback/login terhadap CSRF, replay, session fixation,
 * permintaan cacat, dan kebocoran identitas.
 */
class SsoSecurityTest extends TestCase
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

    // ---------------------------------------------------------------------
    // 1. Callback TANPA memulai flow (tidak ada state di session) → ditolak.
    // ---------------------------------------------------------------------

    public function test_callback_without_prior_login_flow_is_rejected(): void
    {
        // Tidak ada /sso/login sebelumnya → session tidak punya state.
        $this->get('/sso/callback?code=abc123&state=apa-saja')
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // 2. Callback tanpa `code` → ditolak, tidak memanggil Portal.
    // ---------------------------------------------------------------------

    public function test_callback_without_code_is_rejected_without_calling_portal(): void
    {
        $state = $this->startFlow();
        Http::fake();

        $this->get('/sso/callback?state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // 3. `state` kosong / null → ditolak.
    // ---------------------------------------------------------------------

    public function test_callback_with_empty_state_is_rejected(): void
    {
        $this->startFlow();
        Http::fake();

        $this->get('/sso/callback?code=abc123&state=')
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // 4. Session fixation: session id BERGANTI setelah login sukses.
    // ---------------------------------------------------------------------

    public function test_session_is_regenerated_after_successful_login(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        // Mulai sesi (dapat session id sebelum login).
        $this->get('/sso/login');
        $idBefore = session()->getId();

        $state = (string) session('sso.state');
        $this->fakeToken($user->portal_uuid);

        $this->get('/sso/callback?code=abc123&state='.$state)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($idBefore, session()->getId(), 'Session id harus di-regenerate (anti session fixation).');
    }

    // ---------------------------------------------------------------------
    // 5. State disimpan dengan verifier; keduanya dihapus sekali pakai.
    // ---------------------------------------------------------------------

    public function test_state_and_verifier_are_pulled_single_use(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $state = $this->startFlow();
        $this->fakeToken($user->portal_uuid);

        $this->get('/sso/callback?code=abc123&state='.$state)->assertRedirect();

        $this->assertNull(session('sso.state'));
        $this->assertNull(session('sso.code_verifier'));
    }

    // ---------------------------------------------------------------------
    // 6. Verifier TIDAK boleh ikut terkirim di URL authorize (hanya challenge).
    // ---------------------------------------------------------------------

    public function test_authorize_url_never_contains_verifier(): void
    {
        $response = $this->get('/sso/login');
        $location = (string) $response->headers->get('Location');
        $verifier = (string) session('sso.code_verifier');

        $this->assertNotEmpty($verifier);
        $this->assertStringNotContainsString($verifier, $location, 'code_verifier TIDAK boleh ada di URL.');
        $this->assertStringNotContainsString('code_verifier', $location);
        $this->assertStringContainsString('code_challenge=', $location);
    }

    // ---------------------------------------------------------------------
    // 7. Respons token yang cacat (bukan JSON / tanpa portal_uuid) → gagal aman.
    // ---------------------------------------------------------------------

    public function test_callback_handles_malformed_token_response(): void
    {
        $state = $this->startFlow();

        Http::fake([
            'portal.handayani.my.id/oauth/token' => Http::response('bukan-json', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // 8. Token response tanpa `portal_uuid` → ditolak.
    // ---------------------------------------------------------------------

    public function test_callback_rejects_token_response_without_uuid(): void
    {
        $state = $this->startFlow();

        Http::fake([
            'portal.handayani.my.id/oauth/token' => Http::response(['email' => 'x@x.test', 'status' => 'active'], 200),
        ]);

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // 9. Client secret DIKIRIM ke Portal (server-to-server) — verifikasi request.
    // ---------------------------------------------------------------------

    public function test_callback_sends_credentials_server_to_server(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        $state = $this->startFlow();
        $this->fakeToken($user->portal_uuid);

        $this->get('/sso/callback?code=abc123&state='.$state)->assertRedirect();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'portal.handayani.my.id/oauth/token')
                && $request['client_id'] === 'app_test_client'
                && $request['client_secret'] === 'secret_test'
                && $request['redirect_uri'] === 'https://handayani.my.id/sso/callback'
                && $request['code'] === 'abc123';
        });
    }

    // ---------------------------------------------------------------------
    // 10. Tidak ada user_id / token yang diteruskan ke URL mana pun.
    // ---------------------------------------------------------------------

    public function test_no_identity_leaks_into_redirect_urls(): void
    {
        $user = User::factory()->create(['portal_uuid' => (string) Str::uuid()]);
        $user->assignRole('user');

        // URL authorize
        $loginUrl = (string) $this->get('/sso/login')->headers->get('Location');
        $this->assertStringNotContainsString('user_id', $loginUrl);
        $this->assertStringNotContainsString('token', $loginUrl);

        // URL setelah callback sukses
        $state = (string) session('sso.state');
        $this->fakeToken($user->portal_uuid);
        $after = (string) $this->get('/sso/callback?code=abc123&state='.$state)->headers->get('Location');

        $this->assertStringNotContainsString('token', $after);
        $this->assertStringNotContainsString((string) $user->id, $after);
        $this->assertStringNotContainsString((string) $user->portal_uuid, $after);
    }

    // ---------------------------------------------------------------------
    // 11. `/sso/*` mati (404) ketika SSO disabled — termasuk callback.
    // ---------------------------------------------------------------------

    public function test_all_sso_routes_return_404_when_disabled(): void
    {
        config()->set('sso.enabled', false);

        $this->get('/sso/login')->assertNotFound();
        $this->get('/sso/callback?code=x&state=y')->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // 12. Fail closed: Portal HTTP error → user tetap guest.
    // ---------------------------------------------------------------------

    public function test_portal_server_error_fails_closed(): void
    {
        $state = $this->startFlow();

        Http::fake([
            'portal.handayani.my.id/oauth/token' => Http::response(['error' => 'server_error'], 500),
        ]);

        $this->get('/sso/callback?code=abc123&state='.$state)
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // 13. Pesan error generik (tidak membocorkan status akun/link).
    // ---------------------------------------------------------------------

    public function test_failure_message_is_generic(): void
    {
        $state = $this->startFlow();
        $this->fakeToken((string) Str::uuid()); // UUID tak dikenal → unlinked

        $response = $this->get('/sso/callback?code=abc123&state='.$state);

        $response->assertRedirect(route('filament.admin.auth.login'));
        $response->assertSessionHas('sso_error');

        // Pesan tidak boleh memuat detail internal.
        $message = (string) session('sso_error');
        $this->assertStringNotContainsString('portal_uuid', strtolower($message));
        $this->assertStringNotContainsString('uuid', strtolower($message));
    }

    // ---------------------------------------------------------------------
    // 14. Login Logistik LANGSUNG tetap berfungsi saat SSO aktif.
    //    (tanpa memanggil Portal sama sekali)
    // ---------------------------------------------------------------------

    public function test_direct_login_page_still_works_with_sso_enabled(): void
    {
        Http::fake();

        $this->get('/admin/login')->assertOk();
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // 15. Anti open-redirect: `redirect` protocol-relative DITOLAK.
    // ---------------------------------------------------------------------

    public function test_login_rejects_protocol_relative_redirect(): void
    {
        $this->get('/sso/login?redirect=//evil.example.com')->assertRedirect();

        // Nilai berbahaya TIDAK disimpan sebagai intended.
        $this->assertNull(session('sso.intended'));
    }

    public function test_login_rejects_backslash_redirect(): void
    {
        $this->get('/sso/login?redirect=/\\evil.example.com')->assertRedirect();

        $this->assertNull(session('sso.intended'));
    }

    public function test_login_accepts_internal_path_redirect(): void
    {
        $this->get('/sso/login?redirect=/admin/orders')->assertRedirect();

        $this->assertSame('/admin/orders', session('sso.intended'));
    }

    // ---------------------------------------------------------------------
    // 16. Guard transport: SSO_PORTAL_BASE_URL WAJIB HTTPS di non-lokal.
    // ---------------------------------------------------------------------

    public function test_authorize_url_requires_https_outside_local(): void
    {
        $this->app['env'] = 'production';
        config()->set('sso.portal_base_url', 'http://portal.handayani.my.id');

        // Di non-lokal, HTTP ditolak → RuntimeException → respons 500
        // (handler Laravel), TIDAK redirect ke Portal memakai HTTP.
        $this->withoutExceptionHandling();

        $this->expectException(\RuntimeException::class);

        $this->get('/sso/login');
    }

    public function test_authorize_url_allows_https_in_production(): void
    {
        $this->app['env'] = 'production';
        config()->set('sso.portal_base_url', 'https://portal.handayani.my.id');

        $this->get('/sso/login')->assertRedirect();
    }
}
