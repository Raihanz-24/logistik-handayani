<?php

namespace Tests\Feature;

use App\Http\Responses\Auth\LogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Tujuan setelah logout panel Logistik.
 *
 * - SSO aktif   → halaman utama Portal.
 * - SSO nonaktif → login Logistik (perilaku bawaan, login langsung tetap utuh).
 *
 * Kelas ini hanya menguji URL tujuan; tidak menyentuh autentikasi.
 */
class LogoutRedirectTest extends TestCase
{
    private function resolveUrl(): string
    {
        $response = (new LogoutResponse)->toResponse(Request::create('/admin/logout', 'POST'));

        return (string) $response->headers->get('Location');
    }

    public function test_logout_redirects_to_portal_when_sso_enabled(): void
    {
        config()->set('sso.enabled', true);
        config()->set('sso.portal_base_url', 'https://portal.handayani.my.id');

        $this->assertSame('https://portal.handayani.my.id', $this->resolveUrl());
    }

    public function test_logout_uses_baseline_behaviour_when_sso_disabled(): void
    {
        config()->set('sso.enabled', false);
        config()->set('sso.portal_base_url', 'https://portal.handayani.my.id');

        $url = $this->resolveUrl();

        // Bukan Portal; mengarah ke area panel/login Logistik.
        $this->assertStringNotContainsString('portal.handayani.my.id', $url);
        $this->assertStringContainsString(Filament::getLoginUrl(), $url);
    }

    public function test_logout_falls_back_when_portal_base_url_empty(): void
    {
        config()->set('sso.enabled', true);
        config()->set('sso.portal_base_url', '');

        $url = $this->resolveUrl();

        // Base URL kosong → JANGAN arahkan ke string kosong; pakai perilaku bawaan.
        $this->assertStringNotContainsString('portal.handayani.my.id', $url);
        $this->assertStringContainsString(Filament::getLoginUrl(), $url);
    }

    public function test_portal_base_url_trailing_slash_is_trimmed(): void
    {
        config()->set('sso.enabled', true);
        config()->set('sso.portal_base_url', 'https://portal.handayani.my.id/');

        $this->assertSame('https://portal.handayani.my.id', $this->resolveUrl());
    }
}
