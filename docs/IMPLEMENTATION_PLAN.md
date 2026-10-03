# IMPLEMENTATION_PLAN.md — HANDAYANI PORTAL

> Dokumen **Implementation Plan** (STEP 5) — rencana eksekusi phase-by-phase.
> Prasyarat: `AUDIT_EXISTING_LOGISTIK.md`, `ARCHITECTURE.md`, `DATABASE.md`, `SECURITY.md`.
> **Status: RENCANA. Belum ada kode.**
> Tanggal: 2026-10-03
> Keputusan terkait: SSO = Opsi B (server-to-server), tanpa API lintas aplikasi;
> Portal = `portal.handayani.my.id` (Filament, 2 role: `developer` + `user`);
> Logistik = `handayani.my.id` (apex, tidak diubah).

---

## 0. Prinsip Eksekusi

- **Additive only** ke Logistik. Tidak refactor auth/Spatie/business logic.
- **Staging dulu** untuk setiap perubahan yang menyentuh Logistik.
- **STOP & minta approval** sebelum menyentuh Logistik production (lihat §6).
- Portal boleh dibangun bebas (DB & repo terpisah) tanpa mengubah Logistik.
- Tidak menjalankan command destruktif (`migrate:fresh`, `db:wipe`, dll) di production.
- Tidak meminta/ menuliskan secret asli; pakai placeholder `CHANGE_ME`.

---

## 1. Peta Phase (ringkas)

| Phase | Nama | Menyentuh Logistik? | Output | Gate |
|---|---|---|---|---|
| 0 | Audit | ❌ (read-only) | `AUDIT_EXISTING_LOGISTIK.md` | ✅ SELESAI |
| 1 | Bangun Portal | ❌ | Portal jalan (auth, DB, admin) | Tinjau |
| 2 | Access & Linking | ❌ | Kelola akses + link akun | Tinjau |
| 3 | SSO Authorization Server | ❌ | Issue code + PKCE + verifikasi | Test |
| 4 | Konfigurasi aplikasi Logistik di Portal | ❌ | Logistik terdaftar (client) | Test |
| 5 | Connector Logistik (STAGING) | ✅ staging | SSO jalan di staging | **STOP → approval** |
| 6 | Integration & Security Testing | ✅ staging | Semua test lulus | **STOP → approval** |
| 7 | Deployment Logistik Production | ✅ prod | SSO live | **STOP → approval** |
| 8 | Monitoring & Operasional | Monitoring | Observability, alert | — |

> Phase 0–4: nol risiko ke Logistik (tidak menyentuh).
> Phase 5–7: menyentuh Logistik → wajib staging + approval + rollback.
> Phase 8: ops.

---

## PHASE 0 — AUDIT ✅ SELESAI

- [x] `AUDIT_EXISTING_LOGISTIK.md` dibuat (read-only).
- [x] Versi paket, auth, session, DB, PWA, routes terpetakan.
- [x] Titik integrasi & file additive direncanakan.
- [ ] **Inspeksi lanjutan (read-only) sebelum Phase 5:**
  - Nilai `SESSION_DOMAIN` production Logistik (harus host-only, bukan `.handayani.my.id`).
  - Konfirmasi kolom status/aktif user Logistik.
  - Konfirmasi mekanisme deploy production & reverse proxy (HTTPS/headers).

---

## PHASE 1 — BANGUN PORTAL (tanpa menyentuh Logistik)

**Lokasi:** folder terpisah, mis. `D:\laragon\www\handayani-portal` (repo & DB terpisah).

### 1.1 Scaffold
- `composer create-project laravel/laravel handayani-portal` (Laravel 12).
- Set `.env`: `APP_NAME=HANDAYANI PORTAL`, DB baru `handayani_portal`, `APP_DEBUG=false` untuk prod.
- Install Filament (panel admin Portal), Spatie Permission (di DB Portal).

### 1.2 Database (sesuai `DATABASE.md`)
- Migration: `portal_users`, `applications`, `oauth_clients`, `application_access`,
  `application_user_links`, `oauth_authorization_codes`, `oauth_consents` (opsional),
  `audit_logs`, `login_attempts`, `security_events`, `mfa_credentials`,
  `sessions`, `password_reset_tokens`, `notifications` (opsional).
- Semua reversible.

### 1.3 Authentication Portal (§8)
- Login/logout, password reset aman, email verification.
- Hash Laravel. Pesan error generik.
- Session hardening (regenerate, secure cookie).
- Role: `developer`, `user` (Spatie di DB Portal).

### 1.4 Admin (Filament) (§9, §40)
- Filament panel untuk `developer`.
- Halaman: Users, Applications, Access, Links, Audit Log, Security Events, Stats.
- Server-side authorization (policy). MFA untuk `developer`.

### 1.5 Security dasar (§23, §48, §50)
- Security headers, custom error pages, `APP_DEBUG=false`, rate limiting login.
- `login_attempts` + `security_events` aktif.

**Exit criteria PHASE 1:** Portal login berjalan, admin Filament bisa kelola (mock), audit tercatat.

---

## PHASE 2 — APPLICATION ACCESS & ACCOUNT LINKING

### 2.1 Application Registry (§10)
- CRUD aplikasi (name, slug, base_url, redirect_uri, status).
- Generate `client_id` + `client_secret` (secret ditampilkan **sekali**, lalu hash).
- Validasi `redirect_uri` exact (HTTPS, tanpa wildcard).

### 2.2 Application Access (§11)
- Grant/revoke akses user↔aplikasi + audit.
- Cek urutan validasi (yaitu di SSO, Phase 3).

### 2.3 Account Linking (§12, §37, §38)
- UI Super Admin: cari user aplikasi (via connector/API minimal), link/unlink.
- **Tidak** auto-match (nama/email) tanpa konfirmasi & policy.
- Audit link/unlink.

**Exit criteria PHASE 2:** Developer bisa daftar aplikasi, beri akses, dan link akun (dengan data mock).

---

## PHASE 3 — SSO AUTHORIZATION SERVER (sisi Portal)

### 3.1 Authorization Endpoint
- `GET /oauth/authorize` (contoh): validasi **berurut**:
  1) user login, 2) client valid, 3) app active, 4) user active, 5) access active,
  6) redirect exact, 7) PKCE S256 → issue code.
- `state` session-bound, one-time.

### 3.2 Code Store
- Simpan `code_hash`, bind ke client/redirect/user/PKCE, `expires_at` singkat, `consumed_at=NULL`.

### 3.3 Token/Identity Exchange Endpoint (Opsi B)
- `POST /oauth/token` (server-to-server): verifikasi code + `code_verifier` + `client_secret`.
- Set `consumed_at`. Replay → `sso.authorization.code_replayed`.
- Kembalikan **identitas minimal** (external_user_id/email/status) — **bukan** token ke browser.

### 3.4 User Lookup (untuk linking)
- Endpoint internal yang dipanggil Logistik (atau Portal memanggil Logistik, tergantung arah) — minimal fields.

**Exit criteria PHASE 3:** Authorization Code + PKCE S256 + exchange berjalan & lulus unit/feature test.

---

## PHASE 4 — KONFIGURASI APLIKASI LOGISTIK DI PORTAL

- Daftarkan Logistik via Application Registry:
  - name: `Logistik Handayani`
  - slug: `logistik`
  - base_url: `https://handayani.my.id`
  - redirect_uri (exact): `https://handayani.my.id/sso/callback`
  - status: `active`
- Generate client credentials (simpan di `.env` Logistik nanti — **placeholders**).
- Beri `application_access` ke user yang diizinkan + `application_user_links`.

**Exit criteria PHASE 4:** Logistik terdaftar; access & link siap; belum menyentuh Logistik.

---

## PHASE 5 — CONNECTOR LOGISTIK (STAGING) ⛔ STOP → APPROVAL

> **WAJIB staging.** Salin repo + DB Logistik ke staging. Jangan sentuh production.

### 5.1 Komponen BARU di Logistik (additive)
```
routes/sso.php (baru)  ── atau grup baru di routes/web.php
app/Http/Controllers/Sso/SsoCallbackController.php        (baru)
app/Http/Controllers/Api/Sso/UserLookupController.php     (baru)
app/Services/Sso/SsoClientService.php                     (baru)
app/Services/Sso/UserLinkResolver.php                     (baru)
config/sso.php                                            (baru)
```

### 5.2 Perubahan file EXISTING (minimal, perlu diff review)
| File | Perubahan | Alasan | Risiko |
|---|---|---|---|
| `routes/web.php` | +grup `Route::prefix('sso')` | daftarkan callback | Rendah (additif) |
| `routes/api.php` | +grup `Route::prefix('sso')` | user lookup | Rendah (additif) |
| `config/auth.php` | **TIDAK diubah** | — | — |
| `Models/User` | **TIDAK diubah** (ideal) | — | — |

> Aturan RAD §59: tampilkan file/baris/alasan/risiko/impact/rollback/migration sebelum mengubah.

### 5.3 Alur callback (Opsi B)
1. `GET /sso/callback?code=...&state=...` → verifikasi `state` (session).
2. Tukar `code`+`verifier`+`secret` ke Portal (server-to-server).
3. Terima identitas minimal → cari `application_user_links` (resolusi lokal).
4. Jika tidak ada link → tampilkan "belum terhubung" (jangan auto-match).
5. `Auth::login($existingUser)` → `session()->regenerate()` → Dashboard.
6. Audit lokal (via `AuditLogger` yang ada).

### 5.4 Konfigurasi `.env` Logistik (staging)
```
SSO_ENABLED=true
SSO_PORTAL_BASE_URL=https://portal.handayani.my.id
SSO_CLIENT_ID=CHANGE_ME
SSO_CLIENT_SECRET=CHANGE_ME
SSO_REDIRECT_URI=https://staging-handayani... /sso/callback   (sesuai staging)
```

**Gate:** STOP. Sajikan ringkasan perubahan + diff + rollback → minta approval pemilik.

---

## PHASE 6 — INTEGRATION & SECURITY TESTING (STAGING) ⛔ STOP → APPROVAL

### 6.1 Integration test (§31)
Login SSO sukses; user tanpa access ditolak; revoked ditolak; disabled ditolak;
logout; expired session; unlinked → "belum terhubung"; direct login tetap jalan; PWA tetap jalan.

### 6.2 Security test (STEP 8 → `SECURITY_TEST_PLAN.md`)
Code replay, invalid PKCE, invalid redirect, invalid client, expired code,
CSRF, IDOR, mass assignment, XSS, SQLi, open redirect, rate limit, session fixation, enumeration.

### 6.3 Regression
Pastikan auth existing, Spatie, business logic, PWA **tidak berubah**.

**Gate:** semua test lulus → STOP → minta approval deploy.

---

## PHASE 7 — DEPLOYMENT LOGISTIK PRODUCTION ⛔ STOP → APPROVAL

### 7.1 Pre-deploy
1. **Backup DB production** (harus).
2. **Backup aplikasi** (commit/tag; catat changed files).
3. Konfirmasi `SESSION_DOMAIN` production host-only.
4. Siapkan **rollback plan** (`ROLLBACK.md`).
5. Jadwal deploy (paksa re-login? sesuaikan).

### 7.2 Deploy (minimal change)
- Upload file BARU + 2 file route yang ditambah grup.
- Set `.env` production: `SSO_*` (secret asli dari secret manager, **bukan chat**).
- `php artisan config:cache` (+ route:cache bila perlu).
- **Tidak** menjalankan migration destruktif.

### 7.3 Pasca-deploy
- Smoke test: direct login, SSO login, logout, PWA.
- Monitor log & audit.

**Gate:** rollback tersedia; pantau ≥ periode tertentu.

---

## PHASE 8 — MONITORING & OPERASIONAL

- Dashboard security events (Portal).
- Alert rate limit / login gagal / SSO fail.
- Review audit berkala.
- Rotasi secret berkala.
- Onboarding aplikasi berikutnya (pola sama).

---

## 6. STOP-GATES (wajib approval pemilik)

| Gate | Sebelum |
|---|---|
| G1 | Mengubah file existing Logistik (Phase 5) |
| G2 | Menjalankan apa pun di Logistik production (Phase 7) |
| G3 | Mengubah `.env`/config production |
| G4 | Perubahan `SESSION_DOMAIN` (bila ternyata perlu) |
| G5 | Menjalankan migration yang menyentuh DB existing |

Aturan RAD §62: jika butuh ubah DB production / auth besar / hapus data / ubah `.env` prod → **STOP, minta approval, jangan menebak.**

---

## 7. Deliverables (dokumen, sesuai RAD §55)

| Dokumen | Status |
|---|---|
| `AUDIT_EXISTING_LOGISTIK.md` | ✅ |
| `ARCHITECTURE.md` | ✅ |
| `DATABASE.md` | ✅ |
| `SECURITY.md` | ✅ |
| `IMPLEMENTATION_PLAN.md` | ✅ (dokumen ini) |
| `README.md` | ⏳ Phase 1 |
| `DEPLOYMENT.md` | ⏳ Phase 7 |
| `ROLLBACK.md` | ⏳ Phase 7 |
| `SSO_FLOW.md` | ⏳ Phase 3 |
| `SECURITY_TEST_PLAN.md` | ⏳ Phase 6/STEP 8 |
| `PRODUCTION_CHANGE_PLAN.md` | ⏳ Phase 7 |
| `ARCHITECTURE_DECISIONS.md` | ⏳ bertahap |

---

## 8. Estimasi Urutan Kerja (bukan estimasi waktu)

```
Phase 1 (Portal)  ─► Phase 2 (Access/Link) ─► Phase 3 (SSO Server) ─► Phase 4 (Daftar Logistik)
        │
        └──────────────────────────────────────────────────────► Phase 5 (Connector STAGING) ⛔
                                                                        │
                                                                        ▼
                                                              Phase 6 (Testing) ⛔
                                                                        │
                                                                        ▼
                                                              Phase 7 (Deploy PROD) ⛔
                                                                        │
                                                                        ▼
                                                              Phase 8 (Monitoring)
```

---

## 9. Definisi "Siap Mulai Coding"

- [x] Audit selesai
- [x] Arsitektur & keputusan (Opsi B, no cross-app API, domain, Filament, 2 role)
- [x] Database design
- [x] Security model
- [x] Implementation plan (dokumen ini)
- [ ] **Approval pemilik untuk memulai PHASE 1** (buat project Portal baru)

> PHASE 1 **tidak menyentuh Logistik** — hanya membuat project baru di folder terpisah.
> Namun tetap menunggu persetujuan sesuai aturan "jangan melakukan perubahan tanpa persetujuan".
