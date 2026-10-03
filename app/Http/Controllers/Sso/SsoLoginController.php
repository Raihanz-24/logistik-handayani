<?php

namespace App\Http\Controllers\Sso;

use App\Services\Sso\SsoClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /sso/login — memulai alur SSO (dari Logistik ke Portal).
 *
 * Membuat PKCE + state, menyimpannya di session (sekali pakai), lalu
 * mengarahkan browser user ke Portal /oauth/authorize.
 *
 * Catatan: bila user SUDAH login di Logistik, ini akan menautkan identitas
 * SSO ke akun yang sama (atau ke akun hasil resolusi UUID).
 */
class SsoLoginController
{
    public function __invoke(Request $request, SsoClientService $sso): RedirectResponse
    {
        if (! config('sso.enabled')) {
            abort(404);
        }

        $pkce = $sso->makePkce();
        $state = $sso->makeState();

        // Simpan verifier + state di session (one-time).
        $request->session()->put('sso.code_verifier', $pkce['verifier']);
        $request->session()->put('sso.state', $state);
        $request->session()->put('sso.started_at', now()->timestamp);

        // Tujuan setelah sukses (opsional, divalidasi lokal).
        // HANYA path internal absolut ("/..."): tolak protocol-relative
        // ("//evil.com") & backslash ("/\evil.com") yang bisa jadi open redirect.
        $redirect = $request->query('redirect');
        if (
            is_string($redirect)
            && str_starts_with($redirect, '/')
            && ! str_starts_with($redirect, '//')
            && ! str_starts_with($redirect, '/\\')
        ) {
            $request->session()->put('sso.intended', $redirect);
        }

        Log::info('sso.login.start', ['ip' => $request->ip()]);

        return redirect()->away($sso->buildAuthorizeUrl($state, $pkce['challenge']));
    }
}
