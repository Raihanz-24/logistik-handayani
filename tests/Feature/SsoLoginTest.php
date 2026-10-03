<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SSO Login (Logistik sebagai client) — /sso/login.
 *
 * Memverifikasi bahwa Logistik memulai flow Authorization Code + PKCE S256
 * dengan benar, TANPA mengirim identitas user (tidak ada user_id di URL).
 */
class SsoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sso.enabled', true);
        config()->set('sso.portal_base_url', 'https://portal.handayani.my.id');
        config()->set('sso.client_id', 'app_test_client');
        config()->set('sso.redirect_uri', 'https://handayani.my.id/sso/callback');
    }

    public function test_login_redirects_to_portal_authorize_with_pkce_s256(): void
    {
        $response = $this->get('/sso/login');

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertStringStartsWith(
            'https://portal.handayani.my.id/oauth/authorize?',
            $location
        );

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('app_test_client', $query['client_id']);
        $this->assertSame('https://handayani.my.id/sso/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);

        // Tidak ada identitas user di URL.
        $this->assertArrayNotHasKey('user_id', $query);
        $this->assertArrayNotHasKey('token', $query);
    }

    public function test_state_and_verifier_use_pkce_s256(): void
    {
        $response = $this->get('/sso/login')->assertRedirect();

        $state = session('sso.state');
        $verifier = session('sso.code_verifier');

        $this->assertNotEmpty($state);
        $this->assertNotEmpty($verifier);

        $location = $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        // state di URL harus sama dengan yang disimpan di session.
        $this->assertSame($state, $query['state']);

        // code_challenge harus = base64url(sha256(verifier)) — S256.
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $query['code_challenge']);
    }

    public function test_login_404_when_sso_disabled(): void
    {
        config()->set('sso.enabled', false);

        $this->get('/sso/login')->assertNotFound();
    }
}
