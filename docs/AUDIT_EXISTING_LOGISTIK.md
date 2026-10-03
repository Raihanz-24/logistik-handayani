# AUDIT_EXISTING_LOGISTIK.md

> Dokumen hasil **PHASE 0 — AUDIT** untuk persiapan integrasi SSO HANDAYANI PORTAL.
> **Status: READ-ONLY.** Tidak ada kode aplikasi Logistik yang diubah untuk menghasilkan dokumen ini.
> Tanggal audit: 2026-10-03
> Auditor: AI Coding Agent (Claude), atas instruksi pemilik project.

---

## 0. Tujuan & Batasan Audit

Audit ini memetakan kondisi **aktual** aplikasi `Logistik Handayani` (production)
sebelum menambahkan SSO connector. Tujuannya memastikan integrasi bersifat **additive**
dan tidak merusak auth existing, Spatie, PWA, maupun data production.

Batasan:
- Tidak mengubah kode, konfigurasi, `.env`, atau database.
- Tidak menjalankan migration/seed/command destruktif.
- Tidak menyentuh production.

---

## 1. Ringkasan Eksekutif

| Aspek | Temuan | Implikasi untuk SSO |
|---|---|---|
| Framework | Laravel **12.68.0** | Mendukung semua kebutuhan SSO modern |
| PHP | **8.2.30** (ZTS, Windows/Laragon dev) | OK; production cek ulang |
| Auth | Laravel session guard bawaan (`web`) | Connector cukup `Auth::login()` user existing |
| Roles/Permission | **Spatie Permission** (HasRoles di `User`) | JANGAN diubah; Portal tidak menyentuh ini |
| Panel | **Filament v3** (panel id `admin`, path `/admin`) | SSO callback harus route terpisah dari Filament |
| API | **Sanctum** + `routes/api.php` (abilities `api:access`) | Bisa jadi acuan pola service-to-service |
| Session | driver **database**, lifetime 120, cookie `lax` | Session regeneration wajib saat SSO login |
| Database | **MySQL**, `DB_CONNECTION=mysql` | DB terpisah untuk Portal (tidak dishare) |
| PWA | `service-worker.js` v3 + `manifest.webmanifest` | SSO tidak boleh mengganggu SW/manifest |
| Audit | Sudah ada `audit_logs` + `AuditLogger` | Bisa dipakai ulang untuk event SSO lokal |
| CSRF | Middleware web default (VerifyCsrfToken) | Callback SSO harus memperhatikan CSRF |
| Env | `.env` ada, `APP_DEBUG=true` di lokal | Production WAJIB `APP_DEBUG=false` (verifikasi) |

**Kesimpulan awal:** Integrasi SSO **memungkinkan secara teknis** dan dapat dibuat **additive**
(route + controller + service + config baru) tanpa menyentuh auth/Spatie/business logic.
Titik risiko utama ada di bagian **CSRF pada callback**, **session regeneration**,
dan **manajemen kunci publik/signature** untuk verifikasi token dari Portal.

---

## 2. Detail Temuan

### 2.1 Framework & Runtime
| Item | Nilai | Sumber |
|---|---|---|
| Laravel | 12.68.0 | `php artisan --version`, `composer.json` (`laravel/framework: ^12.0`) |
| PHP requirement | `^8.2` | `composer.json` |
| PHP terpasang | 8.2.30 | `php -v` |
| **Filament** | **v3.3.55** | `composer.lock` |
| **Filament Shield** | **3.9.10** (BezhanSalleh) | `composer.lock` |
| **Spatie Permission** | **6.25.0** | `composer.lock` |
| **Sanctum** | **v4.3.3** | `composer.lock` |
| Tinker | `^2.10.1` | `composer.json` |

### 2.2 Sistem Authentication Existing
- Guard default: `web` (driver `session`, provider `users`) — `config/auth.php`.
- Model: `App\Models\User extends Authenticatable implements FilamentUser`.
- Trait: `HasApiTokens, HasFactory, HasRoles, Notifiable`.
- Panel access: `canAccessPanel(): return $this->roles->isNotEmpty();`
- Guest redirect: `redirectGuestsTo(fn () => route('filament.admin.auth.login'))` — `bootstrap/app.php`.
- Login page kustom: `App\Filament\Pages\Auth\Login` (menangani cek `canAccessPanel`).
- **Jalur login lokal** (Direct Login) berjalan lewat Filament panel `admin`.

> ⚠️ **Catatan penting:** SSO connector TIDAK boleh mengubah guard/`config/auth.php`,
> TIDAK mengubah `User` model secara drastis, dan TIDAK mengubah halaman login Filament.
> Connector hanya menambah route baru untuk callback SSO lalu memanggil `Auth::login($existingUser)`.

### 2.3 Skema `users` (tabel existing — JANGAN diubah)
Kolom terdeteksi:
- `id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`
- `username` (varchar 50, unique, NOT NULL) — ditambah migrasi `2026_08_24_030000`
- Tidak ada kolom eksplisit `status`/`is_active` di `users` (perlu konfirmasi — lihat TODO).

> Untuk SSO user discovery, API cukup mengembalikan: `id, name, email, username, status(derived)`.
> JANGAN kembalikan `password`, roles, permissions.

### 2.4 Sesi & Cookie
- `SESSION_DRIVER=database` (tabel `sessions`).
- `SESSION_LIFETIME=120` menit.
- `SESSION_DOMAIN=null` (default host-only).
- `SESSION_ENCRYPT=false` (default).
- `session.php`: `http_only=true`, `same_site=lax`, `secure` mengikuti `SESSION_SECURE_COOKIE`.
- **Production**: pastikan `SESSION_SECURE_COOKIE=true` (HTTPS-only) & set `SESSION_DOMAIN` bila perlu.

### 2.5 CSRF
- Middleware `web` default aktif (`VerifyCsrfToken`), tidak di-exclude di `routes/web.php`.
- Callback SSO dari Portal (redirect cross-site) berpotensi kena CSRF bila method POST.
- **Rekomendasi**: callback SSO dibuat **route stateless** pada grup `api`/tanpa CSRF,
  dengan proteksi `state` + PKCE (bukan bergantung CSRF cookie). Harus didesain hati-hati.

### 2.6 Spatie Permission (JANGAN diubah)
Tabel ada: `permissions`, `roles`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.
- Model `User` memakai `HasRoles`.
- Aplikasi juga punya halaman Peran kustom (`RoleResource`, `RolePermissionCatalog`)
  yang mengganti route Shield bawaan (`/admin/peran`).
- Super admin: role `super_admin`, gate di-intercept (`config/filament-shield.php`, `intercept_gate => 'before'`).

> Portal menangani "boleh masuk aplikasi?".
> Spatie Logistik tetap menangani "boleh ngapain di dalam?". **Pemisahan ini sudah sesuai RAD.**

### 2.7 PWA (harus dijaga saat SSO)
- `public/manifest.webmanifest`: `id=/admin`, `start_url=/admin`, `scope=/`, `display=standalone`.
- `public/service-worker.js`: `CACHE_VERSION='handayani-pwa-v3'`.
  - Precaches: offline.html, manifest, ikon.
  - Cacheable paths: `/build/`, `/css/`, `/js/`, `/images/`, `/fonts/`, `/livewire/livewire.js`.
  - **`request.mode === 'navigate'` → network-first** (fallback `offline.html`); HTML halaman **tidak di-cache**. ✅
  - Hanya aset statis (`isCacheableAsset`) & `/build/assets/` (immutable) yang di-cache.
- **Implikasi**: aman untuk SSO. Route `/sso/*` (HTML/navigation) tidak akan di-cache SW.
  Tidak perlu intervensi SW untuk SSO, kecuali nanti ingin UX offline khusus.

### 2.8 API & Service-to-Service (acuan)
- `routes/api.php`: login `POST /api/login` (throttle 5,1); resource via `auth:sanctum` + `abilities:api:access`.
- Pola Sanctum abilities sudah ada → bisa jadi acuan endpoint user-discovery Portal↔Logistik.
- **JANGAN reuse token user** untuk service-to-service; gunakan client-credentials/secret terpisah.

### 2.9 Audit Log Existing (bisa dipakai ulang)
- Tabel `audit_logs`: `id, user_id, user_name, user_email, event, description, method, route_name,
  path, ip_address, user_agent, status_code, metadata, created_at`.
- Service `App\Services\AuditLogger` dengan method `request()`, `authentication()`, `userCrud()`, `activity()`.
- Middleware `AuditUserActivity` aktif global di grup `web`.
- **Implikasi**: event SSO lokal (login via SSO, mapping gagal, dsb.) bisa dicatat via `AuditLogger::activity()`.
  Jalur Portal↔Logistik tetap butuh audit di sisi Portal (DB Portal).

### 2.10 Konfigurasi & Environment
- `.env` ada di lokal; `APP_ENV=local`, `APP_DEBUG=true`.
- `.gitignore` sudah mengabaikan `.env`, `.env.production`, `vendor`, `storage/*.key`, dll. ✅
- **Tidak ada** file `.env` yang ter-commit (hanya `.env.example`).
- **Domain (info dari pemilik, 2026-10-03):** Logistik production memakai **domain utama
  `handayani.my.id`** (apex). Portal akan memakai `portal.handayani.my.id`.
  - ⚠️ **Catatan cookie:** karena Logistik di apex `handayani.my.id` dan Portal di subdomain,
    cookie Logistik berpotensi terkirim ke subdomain. **Perlu inspeksi `SESSION_DOMAIN`
    production Logistik** (read-only) untuk memastikan bukan `.handayani.my.id`.
  - ⚠️ **Redirect URI SSO** Logistik kemungkinan `https://handayani.my.id/sso/callback` (exact).

### 2.11 Routing
- Web: `/` → redirect ke dashboard admin; `/admin/*` (Filament); `/backup/*`; `/foto-barang-media/*`; `/media/*`.
- API: prefix default `/api`.
- Health: `/up`.
- **Implikasi**: connector SSO Logistik sebaiknya di prefix baru yang terisolasi, mis. `/sso/*`
  (web) dan `/api/sso/*` (service), terpisah dari Filament.

### 2.12 Deployment (perlu verifikasi)
- Ada `Dockerfile`, `docker-compose.yml`, folder `docker/` → deploy kemungkinan container-based.
- Ada hostinger? (folder `origin)` aneh terdeteksi — perlu konfirmasi artefak).
- Scheduler: `backup:run-due` setiap menit (`routes/console.php`).
- **TODO**: konfirmasi mekanisme deploy production (Docker? VPS? shared hosting?), HTTPS, domain final.

---

## 3. Checklist Informasi RAD (status)

| # | Item | Status | Nilai |
|---|---|---|---|
| 1 | Laravel version | ✅ | 12.68.0 |
| 2 | PHP version | ✅ | 8.2.30 (dev) |
| 3 | Auth package | ✅ | Laravel session guard `web` |
| 4 | User model | ✅ | `App\Models\User` (HasRoles, FilamentUser) |
| 5 | Spatie version | ✅ | **6.25.0** |
| 6 | Session driver | ✅ | database |
| 7 | Database driver | ✅ | MySQL |
| 8 | PWA | ✅ | SW v3 + manifest (`/admin`), HTML network-first (tidak di-cache) |
| 9 | Existing routes | ✅ | web/api terdaftar |
| 10 | Existing middleware | ✅ | `AuditUserActivity` global web |
| 11 | Kolom `status` user | ⚠️ | tidak ada kolom eksplisit; perlu konfirmasi |
| 12 | Mekanisme deploy | ⚠️ | Docker terindikasi; perlu konfirmasi |
| 13 | Domain final production | ⚠️ | belum diketahui |
| 14 | HTTPS di production | ⚠️ | perlu konfirmasi |
| 15 | Versi paket (exact) | ✅ | Filament 3.3.55, Shield 3.9.10, Spatie 6.25.0, Sanctum 4.3.3 |

---

## 4. TODO Audit Lanjutan (sebelum/selama implementasi)

1. ~~`composer show` versi paket~~ ✅ Selesai: Filament 3.3.55, Shield 3.9.10, Spatie 6.25.0, Sanctum 4.3.3.
2. Verifikasi tidak ada `.env` ter-commit → ✅ Hasil: hanya `.env.example` yang ter-track (aman).
3. Konfirmasi kolom status/aktif user (apakah ada di tabel lain / kolom `status`).
4. ~~Review `service-worker.js` fetch handler~~ ✅ Selesai: navigation = network-first, HTML tidak di-cache.
5. Konfirmasi mekanisme deploy production & apakah ada reverse proxy (Nginx/Apache) untuk HTTPS/header.
6. Konfirmasi apakah Panel Filament punya domain khusus (saat ini hanya path `/admin`).
7. Cek `config/sanctum.php` & masa berlaku token.
8. Inventarisasi role Spatie yang ada (untuk mapping SSO nanti).
9. Investigasi file aneh bernama `origin)` di root project (artefak git?) — perlu konfirmasi pemilik.

---

## 5. Titik Integrasi SSO yang Direkomendasikan (additive)

> Semua **BARU** — tidak mengubah file existing kecuali disetujui eksplisit.

| Komponen | Usulan lokasi | Jenis |
|---|---|---|
| Route callback SSO | `routes/web.php` (grup baru `/sso`) **atau** `routes/sso.php` baru | NEW |
| Controller SSO | `app/Http/Controllers/Sso/SsoCallbackController.php` | NEW |
| Service SSO client | `app/Services/Sso/SsoClientService.php` | NEW |
| Service user discovery | `app/Services/Sso/UserLookupService.php` | NEW |
| Config SSO | `config/sso.php` | NEW |
| Middleware (opsional) | `app/Http/Middleware/SsoStateGuard.php` | NEW |
| API user-discovery | `routes/api.php` grup `/api/sso` (tambah grup, bukan ubah yang ada) | ADDITIVE |
| Audit event SSO | via `AuditLogger::activity()` yang sudah ada | REUSE |

**File existing yang MUNGKIN perlu disentuh (perlu approval terpisah):**
- `bootstrap/app.php` → HANYA bila perlu daftarkan middleware alias baru (idealnya tidak).
- `routes/web.php` / `routes/api.php` → HANYA menambah grup baru.
- `config/auth.php` → idealnya **tidak diubah**.

---

## 6. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Callback SSO kena CSRF | SSO gagal | Desain callback idempotent + state+PKCE; pisahkan dari CSRF bila perlu |
| Session fixation saat SSO login | Akun diambil alih | `session()->regenerate()` + invalidasi lama (RAD §7) |
| Service-to-service secret bocor | Akses user lookup tak sah | client-credentials + secret di `.env`, bukan hardcode |
| SW men-cache halaman SSO | State/code bocor/rusak | Pastikan `/sso/*` tidak di-cache SW |
| Mengubah `User`/Spatie tak sengaja | Rusak production | Additive only, review diff wajib |
| Token user dipakai untuk service | Privilege berlebihan | Token service terpisah + least privilege |

---

## 7. Kesimpulan Phase 0

- Project Logistik **siap** menerima integrasi SSO secara **additive**.
- **Tidak diperlukan** perubahan besar pada auth, Spatie, atau business logic.
- Ada **beberapa item perlu verifikasi lanjutan** (versi paket, status user, deploy, SW fetch handler).
- **Belum ada perubahan production apa pun** yang dilakukan.
- Langkah berikutnya sesuai RAD: **STEP 2 — ARCHITECTURE** (diagram), (STEP 3 ERD), dst.

---

## 8. Aturan yang Dipegang Selama Audit

- [x] Tidak mengubah kode aplikasi
- [x] Tidak mengubah `.env`
- [x] Tidak menjalankan migration/seed/command destruktif
- [x] Tidak menjalankan `migrate:fresh` / `db:wipe` / `db:seed`
- [x] Tidak meminta credential/secret
- [x] Tidak menyentuh production
