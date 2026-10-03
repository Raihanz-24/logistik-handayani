# PANDUAN DEPLOY SSO DI SERVER (Logistik Handayani)

> Panduan langkah-demi-langkah (STOP-GATE) untuk memasang fitur SSO ke server
> produksi Logistik. Setiap langkah punya **titik henti** — jangan lanjut
> sebelum langkah sebelumnya berhasil.
>
> **Prinsip:** kode SSO bersifat **aditif**. Login langsung Logistik HARUS
> tetap jalan kapan pun, walau Portal mati.
>
> **Kondisi server Anda (terverifikasi):**
> - Folder Logistik sudah `git clone` (bukan manual upload).
> - Remote: `git@github.com:Raihanz-24/logistik-handayani.git` (SSH) ✅
> - PHP: **8.2.34** ✅ (butuh 8.1+, aman)
> - Branch server saat ini: `main`
> - **Ada perubahan lokal** yang harus dibereskan dulu (lihat Langkah 1).

---

## Ringkasan Alur

```
[LOKAL]   push branch  →  merge ke main  →  push main
[SERVER]  backup  →  pull main  →  pop stash (.htaccess)  →  .env (SSO OFF)
          →  migrate  →  UJI login langsung  →  assign UUID  →  tautkan akun
          →  SSO ON  →  UJI SSO
```

> **Catatan:** panduan ini memakai alur **merge ke `main`** (paling rapi untuk
> server yang sudah clone). Bila Anda ingin **uji branch dulu** tanpa merge,
> lihat bagian **Lampiran A** di akhir dokumen.

---

## LANGKAH 0 — Di komputer LOKAL: Merge branch ke `main`

> Dilewati bila Anda sudah merge. Cek cepat:
> `git log --oneline origin/main -3` — bila belum ada commit SSO, jalankan ini.

```bash
cd "D:\laragon\www\logistik handayani"

# Pastikan branch SSO sudah ter-push
git push -u origin feature/sso-connector

# Merge ke main
git checkout main
git pull origin main
git merge feature/sso-connector      # harus fast-forward / bersih
git push origin main

# Balik ke branch kerja (opsional)
git checkout feature/sso-connector
```

✅ **Berhasil bila:** `git push origin main` sukses tanpa konflik.

> Alternatif: merge lewat Pull Request di web GitHub
> (`https://github.com/Raihanz-24/logistik-handayani`).

---

## LANGKAH 1 — Di SERVER: Bereskan perubahan lokal

Saat ini server `main` punya perubahan lokal yang **menghalangi** `git pull`:

```
Changes to be committed:   storage/**/.gitignore, bootstrap/cache/.gitignore
Changes not staged:        public/.htaccess
Untracked:                 public/error_log
```

### 1a. Periksa isi perubahannya (jangan langsung buang!)

```bash
cd ~/logistik-handayani

git diff --cached storage/app/.gitignore
git diff public/.htaccess
git diff --stat
```

**Hasil yang SUDAH terverifikasi di server Anda:**

| File | Perubahan | Kesimpulan |
|---|---|---|
| `storage/**/.gitignore` (11 file) + `bootstrap/cache/.gitignore` | Hanya **mode** `100644` → `100755` (permission). **Nol baris isi** berubah. | Aman dibuang |
| `public/.htaccess` | **Isi logika berubah** (lihat 1b) | **SENGAJA diubah — WAJIB disimpan** |
| `public/error_log` | File untracked (log) | Hapus saja |

### 1b. `public/.htaccess` — perubahan SENGAJA (jangan hilang!)

Perubahan yang terdeteksi di server:

```diff
+# <IfModule mod_headers.c>
+#     <Files "service-worker.js">        ← blok SW dikomentari di server
+#         Header set Cache-Control "no-cache, no-store, must-revalidate"
+#     </Files>
+# </IfModule>
+
+<IfModule mod_setenvif.c>                ← DITAMBAHKAN (aset Vite)
+    SetEnvIf Request_URI "^/build/assets/" handayani_vite_asset=1
+</IfModule>
```

> 🚨 **JANGAN `git reset --hard`.** Perubahan ini = penyesuaian hosting
> (cache aset / Vite). Bila dibuang, fix-nya hilang.

### 1c. Amankan fix `.htaccess` (backup + stash) lalu bersihkan working tree

> ⚠️ Urutan penting: **backup file dulu**, baru stash — agar fix Anda tersimpan
> di luar git dan bisa dikembalikan walau stash bermasalah.

```bash
cd ~/logistik-handayani

# 1. Backup file .htaccess yang AKTIF (di luar git, jaga-jaga)
cp public/.htaccess ~/htaccess-backup-$(date +%Y%m%d)

# 2. Simpan perubahan lokal ke stash (TIDAK menghapus apa pun)
git stash push -u -m "perubahan-lokal-server-sebelum-sso"

# 3. Hapus file untracked error_log (sudah ikut ter-stash, tapi pastikan bersih)
rm -f public/error_log

# 4. Pastikan working tree BERSIH
git status
```

✅ **Berhasil bila:** `git status` → `nothing to commit, working tree clean`.

> 💡 **Lihat isi stash:** `git stash list` → harus ada entri
> `perubahan-lokal-server-sebelum-sso`.
>
> ⚠️ **JANGAN `git stash pop` sekarang.** Lakukan `pop` **setelah** pull
> (lihat Langkah 3b) — agar tidak bentrok dengan pull.

> 🚦 **STOP-GATE 1** — Jangan lanjut bila `git status` belum bersih.

---

## LANGKAH 2 — Di SERVER: Backup (WAJIB)

```bash
cd ~/logistik-handayani

# Backup .env (berisi secret produksi!)
cp .env ".env.bak-$(date +%Y%m%d-%H%M)"

# Backup database — ganti USER & NAMA_DB sesuai isi .env
mysqldump -u USER -p NAMA_DB > ~/logistik-backup-$(date +%Y%m%d).sql
```

Cek nama DB & user dari `.env`:
```bash
grep -E "^DB_(DATABASE|USERNAME)=" .env
```

✅ **Berhasil bila:** file `.sql` dan `.env.bak-*` terbentuk (`ls -lh ~/*.sql`).

> 🚦 **STOP-GATE 2** — Jangan pernah lanjut tanpa backup DB.

---

## LANGKAH 3 — Di SERVER: Pull kode terbaru

```bash
cd ~/logistik-handayani

git fetch origin
git pull origin main
```

✅ **Berhasil bila:** muncul daftar file SSO yang masuk (mis.
`app/Services/Sso/...`, `config/sso.php`, `app/Http/Controllers/Sso/...`).

Cek commit:
```bash
git log --oneline -6
```

> ⚠️ Bila `git pull` gagal ("local changes would be overwritten") → kembalilah
> ke **Langkah 1** (working tree belum bersih).

---

## LANGKAH 3b — Di SERVER: Kembalikan fix `.htaccess` (pop stash)

Setelah pull sukses, kembalikan penyesuaian `.htaccess` yang tadi di-stash:

```bash
cd ~/logistik-handayani

# Lihat daftar stash
git stash list

# Kembalikan perubahan (fix .htaccess + permission .gitignore)
git stash pop
```

Bila `pop` sukses tanpa konflik:

```bash
# Periksa .htaccess sudah kembali memuat fix Vite
grep -n "handayani_vite_asset" public/.htaccess
```

✅ **Berhasil bila:** baris `SetEnvIf ... handayani_vite_asset=1` muncul lagi.

### Bila muncul KONFLIK saat `pop`

Git akan menandai file bermasalah (`UU public/.htaccess`). **Jangan panik:**

```bash
# 1. Pulihkan dari backup yang tadi dibuat (paling aman)
cp ~/htaccess-backup-YYYYMMDD public/.htaccess    # sesuaikan tanggal

# 2. Tandai sudah terselesaikan
git add public/.htaccess

# 3. Buang entri stash yang tersisa
git stash drop
```

### 💡 PENTING — jadikan fix ini PERMANEN (agar tidak hilang lagi)

Fix `.htaccess` ini sudah **dua kali** berupa perubahan lokal. Agar tidak
berulang setiap pull, **commit** ke repo (di server atau lokal):

```bash
# Di server (bila Anda punya akses push) — atau lakukan di lokal
git add public/.htaccess
git commit -m "fix(htaccess): cache aset Vite (SetEnvIf) + nonaktifkan cache SW"
git push origin main
```

> Bila memilih commit dari **lokal**: salin isi `.htaccess` dari server,
> terapkan di repo lokal, lalu commit & push seperti biasa (Anda yang push).

---

## LANGKAH 4 — Di SERVER: Tambah blok SSO ke `.env`

> **PENTING:** **TAMBAHKAN** saja. **JANGAN** menimpa `APP_*`, `DB_*`,
> `MAIL_*`, `SESSION_*`, atau `APP_KEY` yang sudah ada.

Buka `.env` (mis. `nano .env` atau File Manager cPanel), tempel di **paling bawah**:

```dotenv
# --- SSO (Handayani Portal) — Opsi B ---
SSO_ENABLED=false
SSO_PORTAL_BASE_URL=https://portal.handayani.my.id
SSO_CLIENT_ID=
SSO_CLIENT_SECRET=
SSO_REDIRECT_URI=https://handayani.my.id/sso/callback
SSO_STATE_TTL=300
SSO_HTTP_TIMEOUT=10
```

- **`SSO_ENABLED=false`** dulu — wajib. Dinyalakan di Langkah 9.
- `SSO_CLIENT_ID` / `SSO_CLIENT_SECRET` diisi dari Portal (Langkah 8).

✅ **Berhasil bila:** `.env` masih punya `APP_KEY` & `DB_*` yang lama (tidak hilang).

---

## LANGKAH 5 — Di SERVER: Dependency, migrasi, cache

```bash
cd ~/logistik-handayani

# Bila 'composer' tidak tersedia, pakai baris alternatif di bawah.
composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Composer tidak ada di PATH?**
> ```bash
> php -d memory_limit=-1 ~/composer.phar install --no-dev --optimize-autoloader
> ```
> (atau `php ~/bin/composer.phar ...` — sesuaikan lokasi.)

> **`php artisan` error (mis. soal `shell_exec`)?**
> Beberapa hosting memblokir fungsi tertentu. Bila `config:cache`/`route:cache`
> gagal, lewati langkah itu dulu dan **catat pesan error-nya** — laporkan.

✅ **Berhasil bila:** migrasi sukses; kolom `users.portal_uuid` sudah ada
(cek: `php artisan sso:show-uuid --help` tidak error).

> 🚦 **STOP-GATE 3** — Bila migrasi gagal, JANGAN lanjut. Kembalikan backup
> bila perlu (lihat bagian Rollback).

---

## LANGKAH 6 — Di SERVER: UJI TAHAP 1 (SSO masih OFF)

Buka di browser:

- **`https://handayani.my.id/admin/login`** → login langsung Logistik
  **HARUS tetap jalan** seperti biasa.

✅ **Berhasil bila:** admin Logistik bisa login & memakai aplikasi normal.
Ini membuktikan kode SSO **tidak merusak** apa pun.

> ❌ Bila login langsung rusak → **ROLLBACK sekarang** (lihat Rollback).

---

## LANGKAH 7 — Di SERVER: Beri UUID ke user yang akan memakai SSO

```bash
cd ~/logistik-handayani

# Beri UUID (ganti dengan email/username user Logistik)
php artisan sso:assign-uuid nama@example.com

# Lihat UUID-nya (untuk ditempel di Portal)
php artisan sso:show-uuid nama@example.com
```

✅ **Berhasil bila:** UUID tercetak. **Catat UUID-nya.**

> Ulangi untuk setiap user yang perlu SSO.

---

## LANGKAH 8 — Di PORTAL: Daftarkan app & tautkan akun

Login Portal admin:
`https://portal.handayani.my.id/hndy-control-7f3a9c2e`

1. **Menu «Aplikasi»** → pastikan app `logistik` ada & **status = `active`**.
2. Catat **`client_id`** & **`client_secret`** dari app `logistik`
   (masukkan ke `.env` server pada Langkah 4 — `SSO_CLIENT_ID`/`SSO_CLIENT_SECRET`).
3. **Menu «Hak Akses»** → beri akses user Portal ke app `logistik`.
4. **Menu «Tautan Akun»** → buat tautan: user Portal ↔ **UUID** dari Langkah 7.

✅ **Berhasil bila:** setiap user punya (a) Hak Akses ke `logistik`, DAN
(b) Tautan Akun (UUID). Tanpa keduanya, SSO akan **ditolak** di authorize.

---

## LANGKAH 9 — Di SERVER: Nyalakan SSO

```bash
cd ~/logistik-handayani
nano .env          # ubah: SSO_ENABLED=true
php artisan config:cache
```

Buka: **`https://handayani.my.id/sso/login`**

✅ **Berhasil bila:** diarahkan ke Portal, login, lalu kembali terautentikasi
ke Logistik (tanpa `user_id`/token di URL).

> ❌ **`SSO_ENABLED=true` tapi tidak jalan?** Kembalikan ke `false`,
> `php artisan config:cache`, dan login langsung kembali normal.

---

## LANGKAH 10 — Verifikasi akhir

- [ ] `https://handayani.my.id/admin/login` → **login langsung tetap jalan**
- [ ] `https://handayani.my.id/sso/login` → **SSO jalan**
- [ ] URL callback **tidak** memuat `user_id`/`token`/`code` di query
- [ ] `php artisan config:cache route:cache view:cache` sudah dijalankan
- [ ] Hapus file backup lokal server yang sensitif setelah yakin:
      `rm ~/.env.bak-*` (setelah dipastikan tidak dibutuhkan)

---

## Rollback (bila ada masalah)

### A. Matikan SSO saja (paling cepat, tanpa ubah kode)
```bash
cd ~/logistik-handayani
nano .env                     # set SSO_ENABLED=false
php artisan config:cache
```

### B. Kembalikan kode ke commit sebelumnya
```bash
cd ~/logistik-handayani
git log --oneline -6          # catat commit SSO terakhir
git checkout <commit-sebelum-sso>
php artisan optimize:clear && php artisan config:cache
```

### C. Pulihkan `.env` / database dari backup
```bash
cd ~/logistik-handayani
cp .env.bak-YYYYMMDD-HHMM .env         # sesuaikan nama
mysql -u USER -p NAMA_DB < ~/logistik-backup-YYYYMMDD.sql
php artisan optimize:clear && php artisan config:cache
```

---

## Lampiran A — Uji branch TANPA merge ke `main`

Bila Anda ingin menguji `feature/sso-connector` langsung di server:

```bash
cd ~/logistik-handayani

# (Langkah 1 & 2 tetap wajib: bersihkan working tree + backup)

git fetch origin
git checkout feature/sso-connector
git pull origin feature/sso-connector
```

Lanjutkan dari **Langkah 4**. Setelah puas & yakin, kembali dan merge ke `main`
(Langkah 0 di lokal), lalu `git checkout main && git pull origin main`.

---

## Lampiran B — Catatan penting

1. **Jangan pernah** `git pull` saat ada perubahan lokal (working tree kotor).
2. **Composer** mungkin tidak ada di shared hosting → pakai `composer.phar`.
3. **`php artisan`** bisa error di hosting yang memblokir fungsi PHP; catat pesannya.
4. **Secret:** jangan pernah commit `.env`/`.env.bak-*`. Sudah di-`.gitignore`.
5. **Keamanan:** setelah semuanya stabil, rotasi `KRYPTONLAB_API_KEY` dan
   hapus file backup lama yang berisi secret hidup.
6. **Portal & Logistik terpisah:** Portal punya DB sendiri; Logistik hanya
   mengirim/menerima **UUID opaque** — tidak ada password yang disalin.

---

*Dokumen ini untuk deploy **Logistik**. Panduan deploy **Portal** ada di
repo Portal: `docs/DEPLOY_PRODUCTION.md`.*
