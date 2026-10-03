<?php

namespace App\Http\Controllers\Sso;

use App\Services\Sso\SsoClientService;
use App\Services\Sso\UserLinkResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GET /sso/callback — menerima `code` + `state` dari Portal, menukar code
 * (server-to-server), resolusi user, lalu login.
 *
 * ATURAN:
 * - `state` WAJIB cocok dengan session (one-time) → cegah CSRF/replay.
 * - `portal_uuid` tak dikenal → TOLAK (tanpa auto-create).
 * - `status != active` → TOLAK.
 * - Sukses: `Auth::login` + `session()->regenerate()`.
 * - TIDAK ada token/`users.id` yang dipertukarkan (Opsi B).
 */
class SsoCallbackController
{
    public function __invoke(Request $request, SsoClientService $sso, UserLinkResolver $resolver): RedirectResponse
    {
        if (! config('sso.enabled')) {
            abort(404);
        }

        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        // Ambil & hapus dari session (sekali pakai).
        $expectedState = $request->session()->pull('sso.state');
        $codeVerifier = $request->session()->pull('sso.code_verifier');
        $intended = $request->session()->pull('sso.intended');

        // Portal bisa mengembalikan error (mis. user menolak / tak punya akses).
        if (is_string($error) && $error !== '') {
            Log::warning('sso.callback.error', ['error' => $error]); // tanpa detail sensitif

            return $this->fail('Anda tidak memiliki akses ke aplikasi ini melalui Portal.');
        }

        if (! is_string($state) || $expectedState === null || ! hash_equals((string) $expectedState, $state)) {
            Log::warning('sso.callback.state_mismatch', ['ip' => $request->ip()]);

            return $this->fail('Permintaan SSO tidak valid (state). Silakan coba lagi.');
        }

        if (! is_string($code) || $code === '' || ! is_string($codeVerifier) || $codeVerifier === '') {
            return $this->fail('Permintaan SSO tidak lengkap. Silakan coba lagi.');
        }

        try {
            $identity = $sso->exchangeCode($code, $codeVerifier);
        } catch (RuntimeException $e) {
            Log::warning('sso.callback.exchange_failed', ['ip' => $request->ip()]);

            return $this->fail('Gagal memverifikasi SSO. Silakan masuk manual atau coba lagi.');
        }

        if (($identity['status'] ?? 'inactive') !== 'active') {
            return $this->fail('Akun Anda tidak aktif di Portal.');
        }

        $user = $resolver->resolve($identity['portal_uuid']);

        if ($user === null) {
            // TIDAK auto-create (ADR-010/013). Hubungi admin untuk menautkan.
            Log::warning('sso.callback.unlinked', ['ip' => $request->ip()]);

            return $this->fail('Akun Anda belum ditautkan ke aplikasi ini. Hubungi administrator.');
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        Log::info('sso.callback.success', ['user_id' => $user->getKey()]);

        return redirect()->intended($intended ?: route('filament.admin.pages.dashboard'));
    }

    private function fail(string $message): RedirectResponse
    {
        // Arahkan ke login Logistik dengan pesan generik (tidak membocorkan detail).
        return redirect()
            ->route('filament.admin.auth.login')
            ->with('sso_error', $message);
    }
}
