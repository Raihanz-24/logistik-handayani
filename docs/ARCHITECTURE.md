# ARCHITECTURE.md — HANDAYANI PORTAL

> Dokumen arsitektur (STEP 2) untuk **HANDAYANI PORTAL** — Central Identity & Application Access Portal.
> Prasyarat: `AUDIT_EXISTING_LOGISTIK.md` (STEP 1).
> **Status: DESAIN / DOKUMEN.** Belum ada kode yang ditulis.
> Tanggal: 2026-10-03

---

## 1. Prinsip Arsitektur (ringkas)

Satu kalimat:

> **Portal = Identity & Access Gateway** (menentukan siapa & boleh masuk aplikasi mana).
> **Application = Business System** (menentukan apa yang boleh dilakukan setelah masuk).

Konsekuensi:
- Portal **bukan** pemilik business data.
- Portal **bukan** pemilik roles/permissions aplikasi.
- Portal hanya menjawab: **WHO** dan **WHICH APPLICATION**.
- Aplikasi menjawab: **WHAT CAN USER DO**.

---

## 2. Diagram Topologi

```
                         HANDAYANI PORTAL
                    (DB sendiri, auth sendiri)
                  portal.handayani.my.id
                              |
             +----------------+----------------+
             |                |                |
             v                v                v
       LOGISTIK         PERFORMANCE        HOTEL
     HANDAYANI          MONITORING       MANAGEMENT
  handayani.my.id   performance.handayani  hotel.handayani
  (DB existing,        .my.id (DB sendiri)  .my.id (DB sendiri)
   Spatie roles)
             |
      [additive SSO connector]
      /sso/callback (baru)
      /api/sso/* (baru)
```

Prinsip komunikasi: **protokol (OAuth2/OIDC), bukan shared DB.**

```
   ┌──────────────┐        Authorization Code + PKCE (S256)        ┌──────────────┐
   │    PORTAL    │  ─────────────────────────────────────────►    │   LOGISTIK   │
   │  (IdP/Gateway)│  ◄─────────────────────────────────────────    │ (Client/RP)  │
   └──────────────┘        code / token exchange (server-to-server) └──────────────┘
```

---

## 3. Batas Tanggung Jawab (Separation of Concerns)

| Domain | Portal | Aplikasi (Logistik) |
|---|---|---|
| Identity master | ✅ Pemilik | ❌ (hanya external link) |
| Authentication login | ✅ Portal sendiri | ✅ Login lokal sendiri |
| Session aplikasi | ❌ | ✅ Dibuat oleh aplikasi |
| Roles & permissions | ❌ | ✅ Spatie (tetap) |
| Business data | ❌ | ✅ |
| "Boleh masuk aplikasi?" | ✅ | ❌ |
| "Boleh ngapain di dalam?" | ❌ | ✅ |
| Account linking map | ✅ | ❌ (Portal yang simpan) |
| Audit SSO lintas-aplikasi | ✅ | ✅ (lokal, opsional) |

> **JANGAN** mencampur role Portal dengan role Spatie Logistik. Dua sistem terpisah.

---

## 4. Komponen HANDAYANI PORTAL (logis)

```
Portal
├── Auth (login/logout/reset/verify)        → identitas portal
├── Super Admin Console                      → kelola user, aplikasi, access, link, audit
├── Application Registry                     → daftar aplikasi + client credentials
├── Application Access                       → siapa boleh masuk aplikasi mana
├── Account Linking                          → map portal_user ↔ external_user
├── SSO Authorization Server                 → issue code/token (OAuth2 + PKCE)
├── Audit Log                                → jejak semua event sensitif
└── Security Layer                           → rate limit, MFA, headers, session hardening
```

### 4.1 Portal sebagai Authorization Server
- Mendukung **Authorization Code Flow + PKCE S256**.
- `client_id`/`client_secret` per aplikasi (confidential client).
- `redirect_uri` **exact match** terhadap registrasi.
- Menerbitkan **authorization code** (single-use, short-lived).
- Menukar code → token (jika memakai token; bisa juga "identity assertion" server-to-server — lihat §7).

### 4.2 Logistik sebagai OAuth Client / Relying Party
- Punya route callback baru: `GET /sso/callback`.
- Menerima `code` + `state`, memverifikasi `state` (session-bound).
- Menukar `code` ke Portal (server-to-server) beserta `code_verifier`.
- Setelah identitas diterima → cari user existing via `application_user_links` → `Auth::login()` → `session()->regenerate()`.

---

## 5. Alur (Flow) — Sesuai RAD §36

### FLOW A — Direct Login (tetap ada, wajib)
```
User → Logistik /login → auth existing (Filament) → Spatie authorize → Dashboard
```
> Portal **tidak terlibat**. Jika Portal down, flow ini tetap jalan.
> **Portal TIDAK BOLEH menjadi single point of failure untuk login lokal.**

### FLOW B — Portal SSO (sukses)
```
User
  → Portal /login (auth Portal)
  → Portal dashboard
  → Klik "Logistik Handayani"
  → [Portal] check application_access (Budi = ALLOWED?)
  → [Portal] issue authorization code (+PKCE challenge)
  → redirect ke Logistik /sso/callback?code=...&state=...
  → [Logistik] validate code (server-to-server) + PKCE verifier
  → [Logistik] resolve identity → mapping application_user_links
  → [Logistik] Auth::login(existing user) + session regenerate
  → Logistik Dashboard
```
> User **TIDAK** diminta login ulang bila SSO berhasil.

### FLOW C — No Access (ditolak)
```
User → Portal → Klik Logistik
  → application_access = revoked/none
  → Access denied
  → NO SSO, NO LOGIN, NO SESSION CREATED
```

### FLOW D — Linked account missing
```
Portal user Budi punya access Logistik = YES
  TAPI application_user_links tidak ada
  → JANGAN auto-pilih user acak
  → JANGAN match by name
  → JANGAN match by email kecuali policy exact+verified diizinkan
  → Tampilkan: "Account Logistik belum terhubung."
  → Super Admin wajib link manual
```

---

## 6. Urutan Validasi Authorization (kritis — RAD §11)

Urutan **WAJIB** sebelum menerbitkan authorization code:

```
1. Authenticate user (Portal session valid)
2. Check application (client_id terdaftar & active)
3. Check application status = active
4. Check user status = active
5. Check user has application access (application_access = active)
6. Validate redirect_uri EXACT match
7. Validate PKCE challenge ada & method = S256
8. Issue authorization code (random, single-use, short-lived)
```
> **JANGAN** terbitkan code dulu, baru cek access. Cek dulu, baru terbitkan.

Saat **token exchange**, validasi SEMUA (RAD §26):
`client_id`, client auth, `redirect_uri`, code expiry, code unused, PKCE verifier,
application status, user status. Gagal salah satu → **REJECT**.
Setelah dipakai → code **langsung invalid** (replay → `sso.authorization.code_replayed`).

---

## 7. Keputusan: Bagaimana Logistik "menerima" identitas? — ✅ DIPUTUSKAN

> **KEPUTUSAN FINAL (2026-10-03, disetujui pemilik project):** gunakan **Opsi B —
> Server-to-server code exchange**. **TIDAK** memakai token/JWT OIDC penuh (Opsi A).
> **TIDAK** membuat API lintas aplikasi (machine-to-machine).

### Opsi A — Token-based (OIDC style, JWT) — ❌ TIDAK DIPAKAI
- Alasan ditolak: token lewat browser, butuh manajemen kunci (JWKS) + validasi signature,
  lebih banyak komponen baru & lebih banyak titik rawan salah konfigurasi.
  Overkill untuk kebutuhan "login ke dashboard existing".

### ✅ Opsi B — Server-to-server code exchange (DIPILIH)
- Logistik menukar `code` + `code_verifier` + `client_secret` **langsung ke server Portal**.
- Portal mengembalikan **identitas minimal** (mis. `external_user_id`, `email`, `status`).
- **Tidak ada token** yang lewat URL/browser. Browser hanya memegang `code` (single-use, short-lived).
- Tetap memakai **Authorization Code Flow + PKCE S256**.
- Paling minim perubahan di Logistik & paling sedikit komponen baru → paling rendah risiko.

```
Portal ── code (sekali pakai) ──► browser ──► Logistik
Logistik (server) ── code + verifier + secret ──► Portal (server)
Portal (server) ── identitas minimal ──► Logistik (server)
Logistik ── Auth::login(existing user) + session regenerate
```

### Larangan terkait keputusan ini
- ❌ TIDAK menerbitkan access_token/id_token ke browser.
- ❌ TIDAK membuat endpoint API lintas aplikasi (machine-to-machine) untuk saat ini.
- ❌ TIDAK memakai JWT tanpa signature validation (tidak relevan karena JWT tidak dipakai).
- ✅ Hanya SSO login. Cukup untuk kebutuhan saat ini; API lintas aplikasi dibahas terpisah
  bila benar-benar dibutuhkan, dengan review keamanan tersendiri.

---

## 8. Account Linking Model (RAD §12, §37, §38)

```
Portal User (ID 101, Budi)                Logistik User (ID 7, Budi)
        |                                          ^
        |  application_user_links                  |
        +-- portal_user_id = 101                  |
            application_id = logistik             |
            external_user_id = 7  ─────────────────┘
            external_username = budi
            external_email = budi@example.com
            status = linked
```
Aturan:
- `portal_user_id ≠ external_user_id` secara default (DB berbeda).
- Linking **eksplisit oleh Super Admin**, bukan otomatis.
- Matching by email (jika diizinkan policy): **exact + verified + active + konfirmasi**.
- Setiap link/unlink → audit log.

---

## 9. Boundary Keamanan Antar Sistem

```
┌────────────────────────────┐          ┌────────────────────────────┐
│         PORTAL             │          │        LOGISTIK            │
│  - Portal users DB         │          │  - Users DB (existing)     │
│  - Auth Portal             │          │  - Spatie roles            │
│  - OAuth clients           │          │  - Business logic          │
│  - Access & links          │          │  - PWA                     │
│  - Audit                   │          │  - Audit lokal             │
└─────────────┬──────────────┘          └──────────────┬─────────────┘
              │                                        │
              │  HTTPS + OAuth2 code flow (server-s2s) │
              └────────────────┬───────────────────────┘
                               v
        Hanya expose: /api/sso/user-lookup (minimal fields)
        Auth antar-service: client_credentials / signed request
        TIDAK ada: akses DB Logistik dari Portal
```

Prinsip:
- Portal **TIDAK tahu** password DB Logistik.
- User lookup endpoint hanya mengembalikan `id, name, email, username, status`.
- Least privilege (RAD §29).

---

## 10. Komponen yang Akan Disentuh di Logistik (additive only)

> Sesuai RAD §14, §32, §59. Semua **BARU**; file existing hanya ditambah grup bila perlu (perlu approval).

```
Logistik (BARU):
  routes/sso.php                      (atau grup baru di routes/web.php)
  app/Http/Controllers/Sso/SsoCallbackController.php
  app/Http/Controllers/Api/Sso/UserLookupController.php
  app/Services/Sso/SsoClientService.php
  app/Services/Sso/UserLinkResolver.php
  config/sso.php
  tests/Feature/Sso/*                  (BARU)
```

File existing yang **mungkin** perlu +1 grup (perlu persetujuan & review diff):
```
  routes/web.php   → +Route::prefix('sso')->group(...)
  routes/api.php   → +Route::prefix('sso')->group(...)
```
File yang **TIDAK** disentuh: `config/auth.php`, `app/Models/User.php` (kecuali disetujui), Spatie config, business logic.

---

## 11. Non-Functional Requirements

| Aspek | Target |
|---|---|
| Protocol | OAuth2 Authorization Code + PKCE S256 |
| Transport | HTTPS only (production) |
| Session | regenerate saat login SSO; secure+httpOnly+lax |
| Audit | semua event SSO & admin di Portal |
| Failure isolation | Portal down → Direct Login Logistik tetap jalan |
| PWA | tidak terganggu (SW HTML network-first sudah aman) |
| Rate limit | login, SSO authorize, token exchange, user lookup |
| Secrets | via `.env`/secret manager; tidak commit |

---

## 12. Asumsi

1. Production memakai HTTPS.
2. **Domain (DIPUTUSKAN):**
   - Domain utama: **`handayani.my.id`**
   - **Logistik: `handayani.my.id`** (tetap di domain utama, biarkan apa adanya)
   - **Portal: `portal.handayani.my.id`**
   - Aplikasi lain menyusul: mis. `performance.handayani.my.id`, `hotel.handayani.my.id`
3. Portal & Logistik beda host (apex vs subdomain) → cookie session **TIDAK** dishare (sesuai RAD §24).
4. Logistik tetap Filament v3 + Spatie 6.25 (tidak diubah).
5. Portal admin UI memakai **Filament**.
6. Role Portal: **2 saja** → `developer` (superadmin) + `user`.

### 12.1 ⚠️ Implikasi Cookie/SSO (penting — Logistik di apex, Portal di subdomain)

Fakta: `handayani.my.id` (Logistik) adalah **apex**, dan `portal.handayani.my.id` adalah **subdomain**.

- Browser modern memakai "public suffix list": cookie yang di-set untuk `handayani.my.id`
  (tanpa prefix titik) **dapat terkirim** ke subdomain `portal.handayani.my.id`.
  Ini **berbeda** dari skenario `logistik.example.com` + `portal.example.com` yang benar-benar terpisah.
- **Aturan mutlak (RAD §24):** cookie **session Portal TIDAK BOLEH** dipakai sebagai mekanisme SSO,
  dan JANGAN share cookie session lintas host.
- Pastikan cookie session Logistik & Portal **tidak saling bocor**:
  - Logistik: set `SESSION_DOMAIN` agar cookie **host-only** atau domain spesifik `handayani.my.id` (sesuai kebijakan),
    dan **tidak** sengaja diarahkan ke `.handayani.my.id` yang bisa dibaca Portal.
  - Portal: cookie untuk `portal.handayani.my.id` (jangan set ke `.handayani.my.id`).
- SSO tetap murni lewat **Authorization Code + PKCE** — bukan berbagi cookie.
- **Redirect URI Logistik harus EXACT**: kemungkinan `https://handayani.my.id/sso/callback`
  (bukan subdomain lain). Ini yang diregistrasikan di Portal.

> **Dampak PWA:** karena Logistik di apex (bukan subdomain), tidak ada perubahan origin
> untuk SW/manifest. SSO callback berada di origin yang sama dengan Logistik → aman.

> **Tindak lanjut (audit):** perlu konfirmasi nilai `SESSION_DOMAIN` di production Logistik
> agar tidak `.handayani.my.id` (yang akan membuat cookie Logistik terkirim ke Portal).
> Ini **inspeksi read-only**, bukan perubahan.

---

## 13. Keputusan & Pertanyaan Terbuka (menuju STEP 3/4)

Diputuskan:
1. ✅ Identitas antar sistem: **Opsi B (server-to-server code exchange)** — §7.
2. ✅ **Tidak** membuat API lintas aplikasi untuk saat ini.
3. ✅ Domain: Logistik = `handayani.my.id` (apex), Portal = `portal.handayani.my.id`.
4. ✅ Portal admin UI = **Filament**.
5. ✅ Role Portal = `developer` + `user` (2 role).

Tidak ada pertanyaan terbuka untuk STEP 3/4.

---

## 14. Status Dokumen

- [x] Topologi & separation of concerns
- [x] Flow A/B/C/D
- [x] Urutan validasi authorization
- [x] Model account linking
- [x] Boundary keamanan
- [x] Daftar komponen additive
- [ ] STEP 3 — Database Design (ERD + migration plan)
- [ ] STEP 4 — Security Model (threat model)
