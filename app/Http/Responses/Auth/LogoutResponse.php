<?php

namespace App\Http\Responses\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Tujuan setelah logout panel Logistik.
 *
 * - Bila SSO AKTIF (`sso.enabled=true`)  → arahkan ke halaman utama Portal.
 *   Ini menjadikan Portal sebagai "pintu" identitas: setelah keluar dari
 *   Logistik, user dibawa ke Portal (yang otomatis menampilkan login user).
 *
 * - Bila SSO NONAKTIF (`sso.enabled=false`) → arahkan ke login Logistik
 *   seperti perilaku bawaan. Ini memastikan login langsung tetap utuh:
 *   bila Portal mati / SSO dimatikan, user tidak terkunci.
 *
 * Kelas ini TIDAK menyentuh autentikasi/business logic apa pun — hanya
 * mengubah URL tujuan setelah proses logout selesai (aditif).
 */
class LogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (config('sso.enabled') && filled(config('sso.portal_base_url'))) {
            return redirect()->away(rtrim((string) config('sso.portal_base_url'), '/'));
        }

        return redirect()->to(
            Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl(),
        );
    }
}
