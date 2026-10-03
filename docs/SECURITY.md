# SECURITY.md — HANDAYANI PORTAL

> Dokumen **Security Model & Threat Model** (STEP 4).
> Prasyarat: `AUDIT_EXISTING_LOGISTIK.md`, `ARCHITECTURE.md`, `DATABASE.md`.
> **Status: DESAIN. Belum ada kode.**
> Tanggal: 2026-10-03
> Keputusan terkait: SSO = Opsi B (server-to-server), tanpa API lintas aplikasi.

---

## 1. Prinsip Keamanan Utama

1. **Portal = sistem identity paling sensitif.** Jika Portal jebol, akses ke banyak aplikasi terancam.
2. **Defense in depth** — beberapa lapisan kontrol, bukan satu.
3. **Least privilege** — setiap service hanya dapat akses minimum.
4. **Fail secure** — saat ragu/tidak valid → **TOLAK**, bukan lanjut.
5. **Jangan mengorbankan keamanan demi kemudahan** (RAD §61): jika Desain A lebih mudah tapi lebih lemah, pilih Desain B.
6. **Additive only** ke Logistik; tidak menyentuh auth/Spatie/business logic.

---

## 2. Aset yang Dilindungi (Assets)

| Aset | Dampak jika bocor |
|---|---|
| Password portal (hash) | Pengambilalihan akun |
| Client secret aplikasi | Pemalsuan client → SSO abuse |
| Authorization code | Perlu PKCE + single-use untuk membatasi |
| Session cookie portal | Pengambilalihan sesi |
| MFA secret & recovery codes | Bypass MFA |
| `application_access` & links | Eskalasi akses antar aplikasi |
| Audit log | Investigasi forensik |
| **Kredensial DB Logistik** | TIDAK boleh diketahui Portal sama sekali |

---

## 3. Threat Model (STRIDE ringkas)

| Ancaman | Skenario | Kontrol |
|---|---|---|
| **Spoofing** | Attacker pura-pura jadi client/user | client auth + secret; PKCE; state; exact redirect |
| **Tampering** | Ubah `user_id`, code, redirect | Jangan percaya input; bind code ke client+PKCE+user |
| **Repudiation** | Menyangkal aksi | Audit log lengkap (tanpa data sensitif) |
| **Information disclosure** | Bocor token/secret/pesan error | Jangan expose secret; error generik; sanitasi log |
| **Denial of service** | Brute force / spam | Rate limit multi-layer |
| **Elevation of privilege** | Naik jadi developer/admin | Server-side authorization; policy; no mass-assignment |

---

## 4. Kontrol Keamanan per Area

### 4.1 SSO (Authorization Code + PKCE S256)
- Code: **random, cryptographically secure, short-lived, single-use**, disimpan sebagai **hash**.
- Code **bound** ke: `client_id`, `redirect_uri`, `portal_user_id`, `code_challenge`.
- **Exact** redirect URI matching (tanpa wildcard/substring).
- `state`: random, session-bound, one-time, expired, invalid setelah dipakai.
- Token exchange **server-to-server** (Opsi B) — tidak ada token di URL/browser.
- Urutan validasi: **autentikasi → cek aplikasi → app active → user active → access → redirect → PKCE → issue code**.
- Replay code → **REJECT** + log `sso.authorization.code_replayed`.
- ❌ Dilarang: `/sso?user_id=17`, `?api_key=`, implicit grant, token di query string.

### 4.2 CSRF
- Flow SSO memakai **`state` + PKCE**, bukan hanya cookie CSRF.
- Form web Portal (Laravel) tetap pakai CSRF token standar.
- Callback SSO Logistik: stateless by design (validasi code server-to-server), tidak mengandalkan CSRF cookie.

### 4.3 Session
- `session()->regenerate()` setelah login (SSO & lokal) — anti session fixation.
- Cookie: `Secure=true` (production), `HttpOnly=true`, `SameSite=Lax` (atau Strict sesuai kebutuhan).
- **Tidak** share session cookie lintas host (RAD §24) — meski Logistik di apex & Portal di subdomain.
- Expiration wajar; operasi sensitif → re-auth/MFA.
- ❌ Tidak menyimpan token/password/session secret di `localStorage`.

### 4.4 Authentication Portal
- Hash Laravel (bcrypt/argon2id). ❌ MD5/SHA1/custom.
- Email verification (§21) untuk identity email.
- Pesan error login **generik**: "Email atau password tidak valid." (anti user enumeration).

### 4.5 Rate Limiting (§48)
| Endpoint | Batas (indikatif) |
|---|---|
| Login | mis. 5/menit per IP+email |
| Forgot password | mis. 3/menit |
| OTP/MFA | mis. 5/menit |
| SSO authorize | mis. 20/menit per user |
| Token exchange (server) | mis. 60/menit per client |
| User lookup (Logistik→Portal / Portal→Logistik) | ketat per client |
| Admin sensitive ops | ketat per admin |

### 4.6 MFA (§22)
- TOTP standar untuk role **`developer`** (super admin). Opsional untuk user.
- OTP: random, short-lived, one-time, rate limited. ❌ Tidak predictable/tidak expire.
- Recovery codes: hashed/encrypted, tidak plaintext.

### 4.7 Authorization Sisi Portal (§41, §42)
- Semua operasi admin divalidasi **server-side** (policy/middleware).
- ❌ Menyembunyikan tombol saja **tidak cukup**.
- IDOR: semua resource lewat **authorized query/policy**, bukan `find($id)` mentah.
- Mass assignment: `$fillable`/`$guarded` benar. `is_super_admin`/role **hanya** server-side.

### 4.8 Audit Log (§17, §49)
- Catat: login sukses/gagal, logout, admin actions, access granted/revoked, link/unlink,
  SSO events, invalid client/redirect/PKCE, rate limit, suspicious.
- ❌ JANGAN log: password, hash, client secret, token, PKCE verifier, session cookie.
- Metadata **disanitasi**.

### 4.9 HTTP Security Headers (§23)
- Production: CSP (tanpa `unsafe-eval` kecuali terdokumentasi), HSTS, `X-Content-Type-Options`,
  `Referrer-Policy`, `Permissions-Policy`, frame protection.
- ❌ CSP longgar (`script-src *`, `default-src *`).

### 4.10 Error Handling (§50)
- Production: `APP_DEBUG=false`. Custom error pages: 401/403/404/419/429/500.
- ❌ Tidak tampilkan stack trace/SQL/path/env ke user.

### 4.11 Secret Management (§30, §52)
- Semua secret via `.env`/secret manager. ❌ Tidak di source code, ❌ tidak commit.
- Jika secret pernah ter-commit → anggap compromised → **rotate**.
- Service auth antar sistem: **client credentials** (bukan `?api_key=123`).

### 4.12 Input Validation & Injection (§44–§46)
- Semua input divalidasi (type/format/length/existence/authorization/business).
- Query via Eloquent/parameter binding. ❌ Raw SQL dari input.
- Output user-generated di-escape (Blade `{{ }}`, Filament, Livewire, audit viewer).
- Upload (jika ada): validasi MIME/extension/size, random filename, tidak di public executable.

---

## 5. Matriks Ancaman → Kontrol (yang akan diuji di STEP 8)

| # | Ancaman | Kontrol utama | Diharapkan |
|---|---|---|---|
| 1 | Brute force | Rate limit + lockout | Diblokir |
| 2 | Credential stuffing | Rate limit + deteksi + MFA dev | Diblokir |
| 3 | Session fixation | `regenerate()` | Gagal takeover |
| 4 | Session hijacking | Secure/HttpOnly/SameSite + no share | Termitigasi |
| 5 | CSRF | state+PKCE, CSRF form | Ditolak |
| 6 | XSS | escaping + CSP | Netral |
| 7 | SQL injection | binding | Netral |
| 8 | IDOR | policy/authorized query | 403/404 |
| 9 | Mass assignment | fillable + server-side role | Gagal eskalasi |
| 10 | Open redirect | exact match registered | Ditolak |
| 11 | OAuth code replay | single-use (`consumed_at`) | `code_replayed` |
| 12 | PKCE bypass | wajib verifier S256 | Ditolak |
| 13 | Redirect URI manipulation | exact match | Ditolak |
| 14 | Token leakage | Opsi B, no token in URL | N/A by design |
| 15 | User enumeration | pesan generik | Tidak membocorkan |
| 16 | Privilege escalation | server-side authz | Ditolak |
| 17 | Broken access control | policy | 403 |
| 18 | Unauthorized linking | hanya developer + audit | Ditolak/dicatat |
| 19 | Disabled user login | cek status active | Ditolak |
| 20 | Revoked access | cek application_access | Ditolak |
| 21 | Expired code | cek expires_at | Ditolak |
| 22 | Duplicate code | consumed_at | Ditolak |
| 23 | Invalid client | cek client_id+secret | Ditolak |
| 24 | Invalid audience | (Opsi B) identity minimal | N/A |
| 25 | Invalid issuer | (Opsi B) endpoint resmi | Ditolak |
| 26 | Invalid signature | (Opsi B) tidak pakai JWT | N/A |
| 27 | Rate limit bypass | limit per IP+identitas+endpoint | Ditolak |

> Matriks lengkap akan diperluas ke `SECURITY_TEST_PLAN.md` (STEP 8).

---

## 6. Boundary Kepercayaan (Trust Boundaries)

```
[Browser user]  --(HTTPS)-->  [Portal]  --(HTTPS, code+secret, server-s2s)-->  [Logistik]
     untrusted                trusted            trusted (antar server)         trusted
```
- Semua yang datang dari browser = **untrusted**.
- Server-to-server = trusted **hanya jika** client auth + PKCE + exact redirect lolos.
- Portal **tidak** memegang kredensial DB Logistik.

---

## 7. Pemisahan Role (Portal vs Aplikasi)

- Portal roles: `developer`, `user` (DB portal).
- Logistik roles: Spatie (tetap, tidak diubah).
- ❌ Jangan campur. Portal hanya menentukan "boleh masuk", Logistik menentukan "boleh apa".

---

## 8. Risiko Sisa (Residual Risks) & Mitigasi

| Risiko sisa | Mitigasi |
|---|---|
| Cookie Logistik bisa terkirim ke Portal karena apex↔subdomain | Set `SESSION_DOMAIN` Logistik/Portal dengan benar; SSO tidak bergantung cookie; **inspeksi read-only** nilai production |
| Portal jadi target bernilai tinggi | MFA developer, audit ketat, rate limit, monitoring |
| Operator SSO salah konfigurasi | Test ketat (STEP 7/8) + staging + rollback plan |
| User tidak sadar phishing | Edukasi + MFA + domain resmi |

---

## 9. Kepatuhan pada RAD

- [x] §5 Keamanan SSO (code+PKCE, no user_id proof)
- [x] §6 CSRF (state+PKCE)
- [x] §7 Session security
- [x] §8 Portal auth (hash modern)
- [x] §9 Super admin protection
- [x] §11–§13 Akses & linking (no cross-DB)
- [x] §17 Audit log (sanitasi)
- [x] §18–§22 Login security, enumeration, reset, verify, MFA
- [x] §23–§28 Headers, cookie, redirect, code, replay, token
- [x] §29–§30 Least privilege & service-to-service
- [x] §41–§49 Admin authz, IDOR, mass-assignment, validation, injection, XSS, upload, rate limit, logging
- [x] §50–§52 Error handling, env separation, secret management

---

## 10. Status

- [x] Prinsip & aset
- [x] Threat model (STRIDE) & matriks ancaman
- [x] Kontrol per area
- [x] Trust boundaries & risiko sisa
- [ ] STEP 5 — Implementation Plan (phase-by-phase)
- [ ] `SECURITY_TEST_PLAN.md` (STEP 8)
