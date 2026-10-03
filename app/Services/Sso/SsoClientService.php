<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien HTTP ke Authorization Server Portal (Opsi B).
 *
 * - Membangun URL authorize (PKCE S256).
 * - Menukar code secara SERVER-TO-SERVER (tidak ada token ke browser).
 * - TIDAK mencatat/men-log secret, code, atau verifier.
 */
class SsoClientService
{
    /**
     * URL authorize di Portal.
     */
    public function buildAuthorizeUrl(string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'client_id' => (string) config('sso.client_id'),
            'redirect_uri' => (string) config('sso.redirect_uri'),
            'response_type' => 'code',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ]);

        return rtrim((string) config('sso.portal_base_url'), '/')
            .config('sso.authorize_path')
            .'?'.$query;
    }

    /**
     * Tukar authorization code di Portal.
     *
     * @return array{portal_uuid: string, email: ?string, status: string}
     *
     * @throws RuntimeException bila gagal (pesan generik, tanpa bocorkan detail).
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = Http::asForm()
            ->timeout((int) config('sso.timeout', 10))
            ->acceptJson()
            ->post(rtrim((string) config('sso.portal_base_url'), '/').config('sso.token_path'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $codeVerifier,
                'client_id' => (string) config('sso.client_id'),
                'client_secret' => (string) config('sso.client_secret'),
                'redirect_uri' => (string) config('sso.redirect_uri'),
            ]);

        if (! $response->successful()) {
            // JANGAN log body — bisa memuat detail. Pesan generik saja.
            throw new RuntimeException('Penukaran kode SSO gagal.');
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['portal_uuid'])) {
            throw new RuntimeException('Respons SSO tidak valid.');
        }

        return [
            'portal_uuid' => (string) $data['portal_uuid'],
            'email' => isset($data['email']) ? (string) $data['email'] : null,
            'status' => (string) ($data['status'] ?? 'inactive'),
        ];
    }

    /**
     * Generator PKCE (RFC 7636) — dipakai controller /sso/login.
     *
     * @return array{verifier: string, challenge: string}
     */
    public function makePkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * State acak (opaque, session-bound, one-time).
     */
    public function makeState(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
