# SSO Connector (Logistik ↔ Handayani Portal)

Status: **implementasi di branch `feature/sso-connector`** — belum merge/deploy.

Logistik bertindak sebagai **aplikasi client** pada alur **OAuth 2.0 Authorization
Code + PKCE (S256)** dengan **Handayani Portal** sebagai Authorization Server
(**Opsi B**: penukaran code server-to-server; tidak ada token ke browser; tidak
ada `user_id` di URL).

## Prinsip

- **ADDITIVE ONLY** — tidak mengubah auth existing, Spatie roles, Filament
  Shield, atau business logic. Login langsung Logistik tetap berfungsi meski
  Portal down.
- Portal & Logistik **DB terpisah**. Logistik **tidak** mengirim/menerima
  `users.id`; identitas publik = **`portal_uuid`** (UUID milik Portal).
- **Tidak ada auto-create user**. `portal_uuid` tak dikenal → ditolak.
- Logistik **pemilik mapping** `portal_uuid → users.id`.

## Endpoint

| Route | Nama | Fungsi |
|---|---|---|
| `GET /sso/login` | `sso.login` | Buat PKCE + state → redirect ke Portal `/oauth/authorize` |
| `GET /sso/callback` | `sso.callback` | Terima `code`+`state` → tukar token (server-to-server) → resolusi user → login |

Route didaftarkan di `bootstrap/app.php` via `withRouting(then: ...)` dengan
middleware `web`. Tidak mengubah route/middleware lain.

## Logout

Setelah user logout dari panel Logistik, tujuannya bergantung pada status SSO
(diatur oleh `App\Http\Responses\Auth\LogoutResponse`, di-bind di
`AppServiceProvider`, menggantikan `Filament\...\LogoutResponse` bawaan):

| Kondisi | Tujuan setelah logout |
|---|---|
| `SSO_ENABLED=true` | Halaman utama Portal (`SSO_PORTAL_BASE_URL`, mis. `https://portal.handayani.my.id`) |
| `SSO_ENABLED=false` | Login Logistik (perilaku bawaan) |
| `SSO_PORTAL_BASE_URL` kosong | Login Logistik (fallback aman) |

> **Prinsip:** bila Portal mati / SSO dimatikan, logout tetap mengarah ke login
> Logistik → user **tidak terkunci**. Perubahan ini **hanya** mengubah URL tujuan
> setelah logout; tidak menyentuh autentikasi/audit.

## SSO Gate — menutup login langsung saat SSO aktif

Saat `SSO_ENABLED=true`, halaman login Logistik (`/admin/login`) **ditutup**
oleh overlay pop-up (dikunci, tidak bisa ditutup) yang mengarahkan user ke
Portal. Penjagaan berlapis:

1. **UI** — `resources/views/filament/pages/auth/login.blade.php` merender
   overlay `.wm-sso-gate` (gaya di `public/css/filament/admin/login.css`) hanya
   bila `config('sso.enabled')` true.
2. **Server-side** — `App\Filament\Pages\Auth\Login::authenticate()` menolak
   login langsung bila SSO aktif, sehingga tidak bisa ditembus walau UI/CSS
   dilewati (mis. via `curl`).

| Kondisi | Perilaku halaman login Logistik |
|---|---|
| `SSO_ENABLED=true` | Ditutup overlay + tombol "Login dengan Portal Handayani" (`/sso/login`); login langsung **ditolak** di server |
| `SSO_ENABLED=false` | Login langsung **terbuka normal** (tanpa overlay) |

> **Prinsip:** konsisten dengan logout. Bila Portal bermasalah / SSO dimatikan,
> cukup set `SSO_ENABLED=false` → login langsung pulih seketika. Tidak ada
> bypass UI tambahan.
>
> **Darurat:** matikan `SSO_ENABLED` di `.env` lalu `php artisan config:cache`.

## Konfigurasi (`config/sso.php`)

Nilai diambil dari `.env` (JANGAN hardcode):

```env
SSO_ENABLED=false
SSO_PORTAL_BASE_URL=https://portal.handayani.my.id
SSO_CLIENT_ID=
SSO_CLIENT_SECRET=
SSO_REDIRECT_URI=https://handayani.my.id/sso/callback
SSO_HTTP_TIMEOUT=10
```

- `SSO_ENABLED=false` → `/sso/*` = 404 (login langsung tetap jalan).
- `SSO_REDIRECT_URI` **wajib sama persis** dengan yang terdaftar di Portal.

## Migrasi

`2026_10_10_000000_add_portal_uuid_to_users_table.php` — menambah
`users.portal_uuid` (UUID, nullable, unik). Additive; kolom lama tidak diubah.

## Command

```bash
# Buat portal_uuid (idempotent)
php artisan sso:assign-uuid user@example.com

# Lihat portal_uuid
php artisan sso:show-uuid user@example.com
```

Salin UUID hasilnya **secara manual** ke Portal:

```bash
# di repo Portal
php artisan sso:link-account --portal=<username-portal> --app=logistik --uuid=<uuid>
```

## Alur singkat

1. User klik "Buka Aplikasi" di Portal → browser ke
   `https://handayani.my.id/sso/login`.
2. `/sso/login`: buat `code_verifier` + `state` (disimpan di session, sekali
   pakai) → redirect ke Portal `/oauth/authorize`.
3. User (sudah login di Portal) menyetujui → Portal redirect balik ke
   `SSO_REDIRECT_URI` membawa `code` + `state`.
4. `/sso/callback`: validasi `state` (one-time) → `POST /oauth/token`
   (server-to-server, PKCE) → terima `{ portal_uuid, email, status }`.
5. Resolusi `portal_uuid` → user Logistik → `Auth::login` +
   `session()->regenerate()`. UUID tak dikenal → **ditolak** (tanpa auto-create).

## Keamanan

- `state` session **sekali pakai** (`pull`) + `hash_equals` → cegah CSRF/replay.
- PKCE S256 (`code_verifier` 43–128 char, `code_challenge = b64url(sha256(...))`).
- Secret/code/verifier **tidak pernah** di-log.
- Redirect gagal → halaman login Logistik + pesan generik (`sso_error`).
- `SESSION_DOMAIN` produksi **harus host-only** (cookie sesi tidak bocor ke
  subdomain lain).

## Test

```bash
# di CI/staging (punya pdo_sqlite)
php artisan test --filter Sso

# lokal (PHP CLI tanpa pdo_sqlite) - pakai config MySQL lokal
vendor\bin\phpunit -c phpunit.mysql.xml --filter Sso
vendor\bin\phpunit -c phpunit.mysql.xml --filter LogoutRedirect
```

Cakupan: PKCE S256, state mismatch/replay, UUID tak dikenal (tanpa auto-create),
status inactive, kegagalan tukar token, resolver, command, tujuan logout.
