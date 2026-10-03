# DATABASE.md — HANDAYANI PORTAL

> Dokumen desain database (STEP 3) untuk **HANDAYANI PORTAL**.
> Prasyarat: `AUDIT_EXISTING_LOGISTIK.md` (STEP 1), `ARCHITECTURE.md` (STEP 2).
> **Status: DESAIN / BELUM ada migration yang dibuat.**
> Tanggal: 2026-10-03
> Keputusan terkait: SSO = **Opsi B (server-to-server code exchange)**, **tanpa** API lintas aplikasi.

---

## 0. Prinsip

1. **Database TERPISAH** dari Logistik. Portal TIDAK berbagi DB dengan Logistik.
2. Portal TIDAK menyimpan password user Logistik, TIDAK menyalin data bisnis Logistik.
3. Semua tabel baru; tidak ada operasi terhadap DB Logistik.
4. Migration baru = **reversible** (punya `down()`), tidak drop/rename data existing.
5. Nama DB portal (contoh): `handayani_portal`.

---

## 1. ERD (konseptual)

```
┌───────────────┐        ┌──────────────────────┐        ┌──────────────────┐
│  portal_users │        │  application_access  │        │  applications    │
│───────────────│        │──────────────────────│        │──────────────────│
│ id (PK)       │◄──────┐│ id (PK)              │┌──────►│ id (PK)          │
│ name          │       ││ portal_user_id (FK)  ││       │ slug (UNIQUE)    │
│ email (UNIQ)  │       ││ application_id (FK)  ││       │ name             │
│ password      │       ││ status               ││       │ base_url         │
│ email_verified│       ││ granted_by (FK)      ││       │ redirect_uri     │
│ status        │       ││ granted_at           ││       │ client_id (UNIQ) │
│ last_login_at │       ││ revoked_at           ││       │ client_secret_enc│
│ ...           │       │└──────────────────────┘│       │ status           │
└───────┬───────┘       │                        │       └──────────────────┘
        │               │                        │
        │               │  ┌──────────────────────┐  ┌──────────────────┐
        │               │  │ application_user_links│  │ oauth_clients    │
        │               │  │──────────────────────│  │──────────────────│
        │               │  │ id (PK)              │  │ id (PK)          │
        │               │  │ portal_user_id (FK)  │  │ application_id   │
        │               │  │ application_id (FK)  │  │ client_id (UNIQ) │
        │               └─►│ external_user_id     │  │ secret_hash/enc  │
        │                  │ external_username    │  │ redirect_uri     │
        │                  │ external_email       │  │ allowed_grant    │
        │                  │ status (linked/...)  │  │ status           │
        │                  └──────────────────────┘  └──────────────────┘
        │
        │  ┌────────────────────────────┐   ┌──────────────────────┐
        ├─►│ oauth_authorization_codes  │   │ audit_logs           │
        │  │────────────────────────────│   │──────────────────────│
        │  │ id (PK)                    │   │ id (PK)              │
        │  │ code_hash (UNIQ)           │   │ actor_user_id (FK?)  │
        │  │ client_id                  │   │ action               │
        │  │ portal_user_id (FK)        │   │ target_type          │
        │  │ application_id (FK)        │   │ target_id            │
        │  │ redirect_uri               │   │ application_id (FK?) │
        │  │ code_challenge             │   │ ip_address           │
        │  │ code_challenge_method      │   │ user_agent           │
        │  │ expires_at                 │   │ metadata (sanitized) │
        │  │ consumed_at (NULL=belum)   │   │ created_at           │
        │  └────────────────────────────┘   └──────────────────────┘
        │
        │  ┌──────────────────────┐  ┌──────────────────────────┐
        └─►│ login_attempts       │  │ security_events          │
           │──────────────────────│  │──────────────────────────│
           │ id                   │  │ id                       │
           │ email (indexed)      │  │ type                     │
           │ ip_address           │  │ severity                 │
           │ success (bool)       │  │ actor_user_id            │
           │ user_agent           │  │ ip_address               │
           │ created_at           │  │ context (sanitized)      │
           └──────────────────────┘  │ created_at               │
                                     └──────────────────────────┘
   ┌──────────────────────┐  ┌──────────────────┐
   │ mfa_credentials      │  │ sessions (portal)│
   │──────────────────────│  │──────────────────│
   │ id                   │  │ id (PK, string)  │
   │ portal_user_id (FK)  │  │ user_id          │
   │ type (totp)          │  │ ip_address       │
   │ secret_encrypted     │  │ user_agent       │
   │ recovery_codes_enc   │  │ payload          │
   │ confirmed_at         │  │ last_activity    │
   └──────────────────────┘  └──────────────────┘
```

---

## 2. Daftar Tabel & Tujuan

| # | Tabel | Tujuan | Sumber RAD |
|---|---|---|---|
| 1 | `portal_users` | Identitas user Portal | §8 |
| 2 | `applications` | Registry aplikasi | §10 |
| 3 | `application_access` | Siapa boleh masuk aplikasi mana | §11 |
| 4 | `application_user_links` | Mapping portal_user ↔ external_user | §12 |
| 5 | `oauth_clients` | Client credentials aplikasi | §16 |
| 6 | `oauth_authorization_codes` | Authorization code (Opsi B) | §16, §26 |
| 7 | `oauth_consents` | (opsional) consent per user/app | §16 |
| 8 | `audit_logs` | Jejak audit | §17 |
| 9 | `login_attempts` | Deteksi brute force | §18 |
| 10 | `security_events` | Event keamanan (rate limit, suspicious) | §17/§18 |
| 11 | `mfa_credentials` | MFA super admin | §22 |
| 12 | `sessions` | Session portal | §16 |
| 13 | `password_reset_tokens` | Reset password | §20 |
| 14 | `notifications` | (opsional) | §16 |

### Role Portal (DIPUTUSKAN — 2 role saja)
| Role | Deskripsi |
|---|---|
| `developer` | Super admin portal (setara `super_admin`). Kelola user, aplikasi, access, link, audit. Akun berisiko tinggi → MFA/rate limit kuat. |
| `user` | User biasa. Login portal, lihat daftar aplikasi yang boleh diakses, SSO ke aplikasi. |

> Implementasi role Portal bisa memakai Spatie Permission **di DB Portal** (terpisah total
> dari Spatie Logistik). Atau kolom `is_super_admin` + enum `role`. Keputusan detail dicatat di ADR.

> Nama `portal_users` (bukan `users`) sengaja dipakai untuk menghindari kebingungan
> dengan `users` Logistik. Boleh juga `users` di DB portal yang terpisah, tapi nama berbeda lebih jelas.

### Domain & URL (dipakai di seed contoh `applications` / `oauth_clients`)
| Item | Nilai |
|---|---|
| Portal | `https://portal.handayani.my.id` |
| Logistik (apex) | `https://handayani.my.id` |
| Redirect URI Logistik | `https://handayani.my.id/sso/callback` (exact) |
| Aplikasi lain | `https://<nama>.handayani.my.id` |

---

## 3. Detail Kolom Kunci (indikatif)

### 3.1 `portal_users`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| name | string | |
| email | string UNIQUE | identity utama |
| password | string | hash Laravel (bcrypt/argon2) — JANGAN plaintext |
| email_verified_at | timestamp null | §21 |
| status | string/enum | `active` / `inactive` / `suspended` |
| is_super_admin | boolean | default false; **hanya diubah via operasi server-side** (§43) |
| mfa_enabled | boolean | |
| last_login_at | timestamp null | |
| remember_token | string null | |
| created_at/updated_at | timestamps | |

Index: `email` UNIQUE, `status`.

### 3.2 `applications`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| name | string | "Logistik Handayani" |
| slug | string UNIQUE | "logistik" |
| description | text null | |
| base_url | string | `https://handayani.my.id` |
| redirect_uri | string | **exact**, e.g. `https://handayani.my.id/sso/callback` |
| status | string | `active` / `inactive` |
| created_at/updated_at | timestamps | |

> Catatan: `redirect_uri` bisa berada di `applications` **atau** di `oauth_clients`
> (satu aplikasi = satu client). Desain: simpan di `oauth_clients` sebagai sumber tunggal
> validasi exact-match, dan `applications` menyimpan metadata.

### 3.3 `application_access`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| portal_user_id | FK → portal_users | |
| application_id | FK → applications | |
| status | string | `active` / `revoked` |
| granted_by | FK → portal_users null | super admin |
| granted_at | timestamp null | |
| revoked_at | timestamp null | |
| created_at/updated_at | timestamps | |

Unique: `(portal_user_id, application_id)`.
Index: `(portal_user_id, status)`, `(application_id, status)`.

### 3.4 `application_user_links`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| portal_user_id | FK → portal_users | |
| application_id | FK → applications | |
| external_user_id | string | ID user di aplikasi (mis. Logistik id=7) |
| external_username | string null | |
| external_email | string null | |
| status | string | `linked` / `unlinked` |
| linked_by | FK → portal_users null | super admin |
| linked_at | timestamp null | |
| unlinked_at | timestamp null | |
| created_at/updated_at | timestamps | |

Unique: `(application_id, external_user_id)` dan `(portal_user_id, application_id)`.
> JANGAN asumsikan `portal_user_id == external_user_id` (§12).

### 3.5 `oauth_clients`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| application_id | FK → applications | |
| client_id | string UNIQUE | |
| client_secret_hash | string null | **di-hash** untuk verifikasi; TIDAK ditampilkan lagi |
| redirect_uri | string | exact match |
| allowed_grant_types | json | mis. `["authorization_code"]` |
| status | string | `active` / `inactive` |
| created_at/updated_at | timestamps | |

> **Rahasia:** jika butuh menampilkan secret sekali saja → simpan **hash** untuk verifikasi
> (atau `encrypted` bila perlu ditampilkan ulang terbatas). Keputusan desain dicatat di ADR.
> JANGAN expose client secret ke frontend (§10).

### 3.6 `oauth_authorization_codes`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| code_hash | string UNIQUE | **hash** dari code (jangan simpan plaintext) |
| client_id | string | bound ke client |
| portal_user_id | FK → portal_users | |
| application_id | FK → applications | |
| redirect_uri | string | bound + exact |
| code_challenge | string | PKCE |
| code_challenge_method | string | `S256` |
| scope | string null | |
| expires_at | timestamp | **short-lived** (mis. 60–300 detik) |
| consumed_at | timestamp null | NULL = belum dipakai; terisi = sudah → replay ditolak |
| created_at/updated_at | timestamps | |

Index: `code_hash` UNIQUE, `expires_at`.

> **Opsi B**: SETELAH code ditukar (server-to-server), code → `consumed_at` diisi,
> identitas dikembalikan ke Logistik. TIDAK ada token disimpan/dikirim ke browser.

### 3.7 `audit_logs`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| actor_user_id | FK → portal_users null | |
| action | string | mis. `sso.authorization.success` |
| target_type | string null | |
| target_id | string/int null | |
| application_id | FK → applications null | |
| ip_address | string(45) null | |
| user_agent | text null | |
| metadata | json null | **disanitasi** |
| created_at | timestamp | |

**DILARANG di metadata:** password, password hash, client secret plaintext, access/refresh
token, PKCE verifier, session cookie (§17).

### 3.8 `login_attempts`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| email | string (indexed) | untuk deteksi per-identitas |
| ip_address | string(45) | |
| success | boolean | |
| user_agent | text null | |
| created_at | timestamp | |

### 3.9 `security_events`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| type | string | `rate_limit`, `suspicious_activity`, dll |
| severity | string | `low`/`medium`/`high` |
| actor_user_id | FK nullable | |
| ip_address | string(45) | |
| context | json null | disanitasi |
| created_at | timestamp | |

### 3.10 `mfa_credentials` (§22)
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| portal_user_id | FK → portal_users | |
| type | string | `totp` |
| secret_encrypted | text | **encrypted** at rest |
| recovery_codes_encrypted | text null | hashed/encrypted, TIDAK plaintext |
| confirmed_at | timestamp null | |
| created_at/updated_at | timestamps | |

### 3.11 `sessions`, `password_reset_tokens`, `notifications`
- `sessions`: standar Laravel (driver database), di DB portal.
- `password_reset_tokens`: standar Laravel; token **hashed** (§20).
- `notifications`: opsional.

---

## 4. Migration Plan (urutan; belum dijalankan)

> Semua di DB **Portal**. Reversible. Tidak menyentuh DB Logistik.

```
0001_..._create_portal_users_table
0002_..._create_applications_table
0003_..._create_oauth_clients_table
0004_..._create_application_access_table
0005_..._create_application_user_links_table
0006_..._create_oauth_authorization_codes_table
0007_..._create_oauth_consents_table            (opsional)
0008_..._create_audit_logs_table
0009_..._create_login_attempts_table
0010_..._create_security_events_table
0011_..._create_mfa_credentials_table
0012_..._create_sessions_table
0013_..._create_password_reset_tokens_table
0014_..._create_notifications_table             (opsional)
```

Aturan migration (§33):
- [x] Ada `down()`
- [x] Tidak drop tabel existing (semua tabel BARU di DB baru)
- [x] Tidak rename kolom tanpa alasan
- [x] Tidak ubah data massal

---

## 5. Keamanan Data (ringkas)

| Data | Perlakuan |
|---|---|
| Password portal | Hash Laravel (bcrypt/argon2id) |
| Client secret aplikasi | Hash (verifikasi) atau encrypted; tidak plaintext |
| Authorization code | Disimpan sebagai **hash**, single-use, short-lived |
| PKCE verifier | **Hanya** diverifikasi, TIDAK disimpan di audit |
| MFA secret | Encrypted at rest |
| Recovery codes | Hash/encrypted, tidak plaintext |
| Audit metadata | Disanitasi: tanpa secret/token/password |

---

## 6. Yang TIDAK boleh ada di DB Portal

- ❌ Password/hash user Logistik
- ❌ Business data Logistik (stok, mutasi, dsb.)
- ❌ Roles/permissions Spatie Logistik
- ❌ Sesi user Logistik
- ❌ Koneksi/credential DB Logistik

---

## 7. Relasi ke SSO Opsi B (ringkas)

```
Login Portal OK
  → cek application_access (active)
  → buat oauth_authorization_codes (code_hash, PKCE challenge, expires, consumed_at=NULL)
  → redirect ke Logistik /sso/callback?code=...&state=...
  → Logistik tukar code (server-to-server) ke Portal
      → Portal verifikasi: code_hash, client, redirect_uri exact, PKCE verifier,
        expires, belum consumed, app active, user active
      → tandai consumed_at = now
      → cari application_user_links (linked)
      → kembalikan identitas minimal ke Logistik
  → Logistik Auth::login(user existing) + session regenerate
```
Tidak ada tabel token tersimpan untuk browser; tidak ada API lintas aplikasi.

---

## 8. Status & Lanjutan

- [x] ERD
- [x] Daftar tabel & kolom
- [x] Migration plan (urutan)
- [x] Aturan keamanan data
- [ ] STEP 4 — Security Model (threat model)
- [ ] STEP 5 — Implementation Plan
- [ ] Keputusan desain client secret (hash vs encrypted) → catat di ADR
