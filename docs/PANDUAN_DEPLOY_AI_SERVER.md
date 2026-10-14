# PANDUAN DEPLOY ASISTEN AI + BARANG PASAR (Logistik Handayani)

> Panduan langkah-demi-langkah (STOP-GATE) untuk memasang fitur **Asisten AI
> (chat & voice DeepSeek)** dan **toggle Barang Pasar** ke server produksi
> Logistik. **Ikuti berurutan** dari Langkah 0 → 10. Setiap langkah punya
> **titik henti (STOP-GATE)** — jangan lanjut sebelum langkah sebelumnya berhasil.
>
> **Prinsip:** seluruh kode bersifat **ADITIF & NON-DESTRUKTIF**:
> - Fitur AI **default OFF** (`AI_ENABLED=false`) — tidak muncul sampai dinyalakan.
> - Barang Pasar berupa **1 kolom boolean baru** dengan default `false` →
>   perilaku data lama **tidak berubah** sampai ditoggle manual.
> - Tidak ada perubahan dependensi (`composer`/`npm`) dan tidak ada data yang
>   dihapus. Login & fitur lama HARUS tetap jalan kapan pun.

---

## Kondisi yang sudah terverifikasi

**Server (shared hosting, SSH):**
- Folder Logistik sudah `git clone` (bukan upload manual).
- Remote: `https://github.com/Raihanz-24/logistik-handayani.git`
- Branch server saat ini: `main`
- `main` **sudah berisi SSO** (commit `d23a0c9`).
- ❗ Server punya **perubahan lokal** `.htaccess`/permission → kerjakan Langkah 1.

**Lokal Anda (terverifikasi):**
- Branch kerja: `feature/ai-assistant` @ `3ca8410` (**4 commit**, belum di-remote).
- `main` lokal = `d23a0c9` (= `origin/main`). `main` adalah **leluhur** branch ini →
  merge/update akan **FAST-FORWARD** (0 konflik, 0 commit baru di `main`).
- **Tidak ada** perubahan `composer.json`/`package.json` → server **tidak perlu**
  `composer install`/`npm install` ulang.
- `public/build` **sudah ter-commit** → server **tidak perlu** `npm run build`.
- `.env` **tidak ter-track** (aman) → konfigurasi AI diisi manual di server.

---

## Peta Alur Lengkap

```
╔══════════════════════════════════════════════════════════════════════╗
║  DI KOMPUTER LOKAL (Windows)                                         ║
║                                                                      ║
║  Langkah 0A  push branch feature/ai-assistant                        ║
║  Langkah 0B  merge feature/ai-assistant → main  (fast-forward)       ║
║  Langkah 0C  push main ke GitHub                                     ║
╚══════════════════════════════════════════════════════════════════════╝
                                │
                                ▼  (kode AI sekarang ada di main)
╔══════════════════════════════════════════════════════════════════════╗
║  DI SERVER (shared hosting, SSH)                                     ║
║                                                                      ║
║  Langkah 1   bersihkan perubahan lokal (backup + stash .htaccess)    ║
║  Langkah 2   backup .env + DATABASE                                  ║
║  Langkah 3   git pull origin main                                    ║
║  Langkah 3b  git stash pop  (kembalikan fix .htaccess)               ║
║  Langkah 4   tambah blok AI_* ke .env   (AI_ENABLED=false)           ║
║  Langkah 5   migrate + cache                                         ║
║  Langkah 6   UJI: login & fitur lama HARUS tetap jalan               ║
║  Langkah 7   (opsional) seed pengetahuan AI                          ║
║  Langkah 8   aktifkan AI_ENABLED=true -> uji menu Asisten AI         ║
║  Langkah 9   uji voice (WAJIB HTTPS) + toggle Barang Pasar           ║
║  Langkah 10  verifikasi akhir                                        ║
╚══════════════════════════════════════════════════════════════════════╝
```

> **Catatan:** panduan ini memakai alur **merge ke `main`** (paling rapi untuk
> server yang sudah clone). Bila ingin **uji branch dulu** tanpa merge, lihat
> **Lampiran A**.

---

# BAGIAN 1 — DI KOMPUTER LOKAL (Windows)

## LANGKAH 0A — Pastikan branch AI sudah ter-push

```bash
cd "D:\laragon\www\logistik handayani"

# Branch kerja saat ini & statusnya
git rev-parse --abbrev-ref HEAD     # harus: feature/ai-assistant
git status --short                  # harus BERSIH (kosong)

# Push branch (bila belum; aman diulang)
git push -u origin feature/ai-assistant
```

✅ **Berhasil bila:** output menampilkan branch `feature/ai-assistant` sudah
ter-push (atau `Everything up-to-date`).

> ⚠️ Bila `git status --short` **tidak kosong**, commit dulu:
> ```bash
> git add <file-file-yang-muncul>
> git commit -m "chore: siapkan merge Asisten AI"
> ```

---

## LANGKAH 0B — Merge `feature/ai-assistant` → `main` (fast-forward)

> Dipastikan **fast-forward** (tanpa konflik): `main` tidak punya commit yang
> belum ada di `feature`. Yang ikut masuk: **4 commit**
> (`fed1817` Barang Pasar, `386141d` AI chat+voice, `0478482` & `3ca8410`
> perbaikan diagnosa/indikator suara).

```bash
cd "D:\laragon\www\logistik handayani"

# 1. Pindah ke main & pastikan terbaru
git checkout main
git pull origin main

# 2. Merge branch AI (harus fast-forward)
git merge feature/ai-assistant

# 3. Lihat hasilnya — 4 commit AI/Barang Pasar harus muncul di atas
git log --oneline -6
```

✅ **Berhasil bila:** output `git merge` menyebut **`Fast-forward`** (bukan merge
commit / konflik), dan `git log` menampilkan commit AI di atas `d23a0c9`.

> ❗ Bila muncul `CONFLICT` (tidak diharapkan), **berhenti** dan laporkan —
> jangan lanjut ke push.

---

## LANGKAH 0C — Push `main` ke GitHub

```bash
# Masih di branch main
git push origin main

# Verifikasi remote sudah berisi AI
git log --oneline -3 origin/main

# Balik ke branch kerja (opsional; agar tidak bingung)
git checkout feature/ai-assistant
```

✅ **Berhasil bila:** `git push origin main` sukses, dan `origin/main` kini
menunjuk ke commit AI (mis. `3ca8410`), **bukan** `d23a0c9`.

> 🚦 **STOP-GATE 0** — Jangan lanjut ke server bila `origin/main` belum berisi
> commit AI.

**Alternatif:** alih-alih 0B–0C di terminal, Anda bisa merge via **Pull Request
di web GitHub** → `feature/ai-assistant` → `main` → **Merge pull request**.

---

# BAGIAN 2 — DI SERVER (shared hosting)

## LANGKAH 1 — Bereskan perubahan lokal

Bila server `main` masih punya perubahan lokal yang menghalangi `git pull`
(permission `storage/.gitignore`, `public/.htaccess`, `public/error_log`),
kerjakan langkah aman berikut.

> 🚨 **JANGAN `git reset --hard`** — bisa menghapus penyesuaian hosting
> (`.htaccess` untuk cache aset Vite).

```bash
cd ~/logistik-handayani

# 1. Periksa apa yang berubah (jangan langsung buang)
git status
git diff

# 2. Backup .htaccess yang AKTIF (jaga-jaga, di luar git)
cp public/.htaccess ~/htaccess-backup-$(date +%Y%m%d)

# 3. Simpan perubahan lokal ke stash (TIDAK menghapus apa pun)
git stash push -u -m "perubahan-lokal-server-sebelum-ai"

# 4. Hapus file untracked log bila ada
rm -f public/error_log

# 5. Pastikan working tree BERSIH
git status
```

✅ **Berhasil bila:** `git status` → `nothing to commit, working tree clean`.

> ⚠️ **JANGAN `git stash pop` sekarang.** Lakukan `pop` **setelah** pull
> (lihat Langkah 3b) agar tidak bentrok.

> 🚦 **STOP-GATE 1** — Jangan lanjut bila `git status` belum bersih.

---

## LANGKAH 2 — Backup (WAJIB)

```bash
cd ~/logistik-handayani

# Backup .env (berisi secret produksi!)
cp .env ".env.bak-$(date +%Y%m%d-%H%M)"

# Cek nama DB & user dari .env
grep -E "^DB_(DATABASE|USERNAME)=" .env

# Backup database — ganti USER & NAMA_DB sesuai isi .env
mysqldump -u banksam6_raihan24 -p banksam6_logistikhandayani \
  > ~/logistik-backup-$(date +%Y%m%d).sql
```

✅ **Berhasil bila:** file `.sql` dan `.env.bak-*` terbentuk (`ls -lh ~/*.sql`).

> 🚦 **STOP-GATE 2** — Jangan pernah lanjut tanpa backup DB.

---

## LANGKAH 3 — Pull kode terbaru

```bash
cd ~/logistik-handayani

git fetch origin
git pull origin main
```

✅ **Berhasil bila:** muncul daftar file baru, mis.
`app/Services/Ai/...`, `app/Filament/Pages/AsistenAi.php`, `config/ai.php`,
`database/migrations/2026_10_11_*`, `2026_10_12_*`, `public/build/assets/*`.

```bash
git log --oneline -6
```

> ⚠️ Bila `git pull` gagal ("local changes would be overwritten") → kembalilah
> ke **Langkah 1** (working tree belum bersih).

---

## LANGKAH 3b — Kembalikan fix `.htaccess` (pop stash)

```bash
cd ~/logistik-handayani

git stash list
git stash pop
```

Bila `pop` sukses, pastikan fix masih ada:

```bash
grep -n "handayani_vite_asset" public/.htaccess
```

✅ **Berhasil bila:** baris `SetEnvIf ... handayani_vite_asset=1` muncul lagi.

### Bila muncul KONFLIK saat `pop`

```bash
# 1. Pulihkan dari backup
cp ~/htaccess-backup-YYYYMMDD public/.htaccess     # sesuaikan tanggal
# 2. Tandai selesai
git add public/.htaccess
# 3. Buang entri stash sisa
git stash drop
```

> 💡 Agar tidak berulang tiap pull, pertimbangkan **commit** fix `.htaccess` ke
> repo (dari lokal lalu push).

---

## LANGKAH 4 — Tambah blok AI ke `.env`

> **PENTING:** **TAMBAHKAN** saja. **JANGAN** menimpa `APP_*`, `DB_*`,
> `MAIL_*`, `SESSION_*`, `SSO_*`, atau `APP_KEY` yang sudah ada.

Buka `.env` (mis. `nano .env` atau File Manager cPanel), tempel di **paling bawah**:

```dotenv
# --- Asisten AI (DeepSeek via gateway KryptonLab) ---
AI_ENABLED=false
AI_PROVIDER=kryptonlab
AI_BASE_URL=https://api.kryptonlab.id/v1
AI_API_KEY=
AI_MODEL=deepseek-v4.1-flash
AI_CHAT_PATH=/chat/completions
AI_MODELS_PATH=/models
AI_TIMEOUT=60
AI_CONNECT_TIMEOUT=10
AI_RETRY_TIMES=2
AI_RETRY_SLEEP_MS=500
AI_MAX_TOKENS=1024
AI_TEMPERATURE=0.2
AI_MAX_TOOL_ITERATIONS=6
AI_HISTORY_LIMIT=20
AI_VOICE_LANG=id-ID
AI_VOICE_RATE=1.0
AI_VOICE_AUTO_SPEAK=false
AI_VOICE_READ_SUMMARY=true
```

- **`AI_ENABLED=false`** dulu — wajib. Dinyalakan di Langkah 8.
- Isi **`AI_API_KEY`** dari gateway KryptonLab saat siap menyalakan AI.

✅ **Berhasil bila:** `.env` masih punya `APP_KEY`, `DB_*`, dan `SSO_*` yang lama.

---

## LANGKAH 5 — Migrasi & cache

> Tidak perlu `composer install`/`npm run build` (tidak ada perubahan dependensi
> dan aset build sudah ada di repo).

```bash
cd ~/logistik-handayani

php artisan migrate --force

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> 🚨 **JANGAN** `migrate:fresh`, `migrate:refresh`, atau `db:wipe`.

✔️ Migrasi yang berjalan (semuanya **additive**):

| Migrasi | Efek |
|---|---|
| `2026_10_11_000000_create_ai_assistant_tables` | Membuat tabel `ai_conversations`, `ai_messages`, `ai_pending_actions` |
| `2026_10_11_010000_create_ai_governance_tables` | Membuat tabel `ai_usages`, `ai_settings`, `ai_knowledge_entries` |
| `2026_10_12_000000_add_is_barang_pasar_to_barangs_table` | Menambah kolom `barangs.is_barang_pasar` (boolean, default `false`) |
| `2026_10_13_000000_add_ai_alias_to_barangs_table` | Menambah kolom `barangs.ai_alias` (string, nullable) untuk nama panggilan barang oleh Asisten AI |

Cek status:
```bash
php artisan migrate:status | grep -E "ai_|is_barang_pasar|ai_alias"
```
✅ **Berhasil bila:** keempatnya **`Ran`**.

> 🚦 **STOP-GATE 3** — Bila migrasi gagal, JANGAN lanjut. Kembalikan backup bila
> perlu (lihat bagian Rollback).

---

## LANGKAH 6 — UJI TAHAP 1 (AI masih OFF)

Buka di browser:

- **`https://handayani.my.id/admin/login`** → login langsung Logistik
  **HARUS tetap jalan** seperti biasa.
- Cek menu **Barang** (master data) masih normal, dan toggle **"Barang Pasar"**
  muncul di kolom tabel (default **OFF** untuk semua barang lama).

✅ **Berhasil bila:** admin bisa login & memakai aplikasi normal, dan tidak ada
barang lama yang mendadak berubah perilaku stoknya.

> ❌ Bila ada yang rusak → **ROLLBACK sekarang** (lihat Rollback).

---

## LANGKAH 7 — (Opsional) Seed pengetahuan & pengaturan AI

Hanya bila ingin mengisi pengaturan scope + aturan contoh (termasuk aturan
**"Barang pasar hanya untuk belanja"**). **Sekali saja** — idempotent (aman
diulang).

```bash
cd ~/logistik-handayani
php artisan db:seed --class="Database\Seeders\AiAssistantSeeder" --force
```

> ⚠️ Seeder ini **TIDAK** dipanggil oleh `DatabaseSeeder`, jadi seeding existing
> tidak berubah. Bila ingin menulis aturan sendiri, lewati langkah ini dan isi
> lewat menu **Pengaturan AI** di aplikasi.

---

# BAGIAN 3 — MENYALAKAN & MENGUJI

## LANGKAH 8 — Aktifkan AI

```bash
cd ~/logistik-handayani
nano .env          # ubah: AI_ENABLED=true  (dan isi AI_API_KEY)
php artisan config:clear && php artisan config:cache
```

Buka menu **Bantuan → Asisten AI** (`https://handayani.my.id/asisten-ai`).

✅ **Berhasil bila:** halaman Asisten AI terbuka dan bisa mengirim pesan
(chat) dan menerima balasan dari model.

> **Akses menu:** selain `super_admin`, user butuh salah satu permission
> `view_any_barang`, `view_any_barang::lokasi`, atau `view_any_mutasi`.
> Tool AI menghormati permission masing-masing user (mis. tool tulis butuh
> `create_mutasi` / `create/update_kalkulator_belanja`).
>
> ❌ **`AI_ENABLED=true` tapi halaman error?** Kembalikan ke `false`,
> `php artisan config:cache`, lalu laporkan pesan error-nya.

---

## LANGKAH 9 — Uji Voice & Barang Pasar

### 9a. Voice (input suara)
- **WAJIB HTTPS** (`https://handayani.my.id`). Web Speech API **tidak jalan** di
  `http://` (kecuali `localhost`/`127.0.0.1`).
- **Hanya Google Chrome / Microsoft Edge.** Firefox & Opera **tidak didukung**.
- Buka **Asisten AI** → klik **mic** → bicara. Gelombang merah di panel akan
  **membesar** saat suara terekam.
- Bila tak ada teks: buka **Console (F12)** dan periksa baris
  `[Asisten AI][voice] ...` (berisi language, nama perangkat, level, secure
  context) — kirimkan bila butuh bantuan.
- Bila gelombang **tidak membesar** walau bicara dekat: periksa **mikrofon
  default** (ikon mikrofon di address bar Chrome) & pastikan tidak bisu.

### 9b. Barang Pasar
- Buka **Barang** (master data) → aktifkan toggle **"Barang Pasar"** pada 1
  barang uji.
- Verifikasi perilaku (barang pasar):
  - **Tidak** muncul di daftar **Stok Gudang** / rekap stok.
  - **Tidak** dihitung di **prioritas restock (SAW)**.
  - **Tidak** muncul di widget **"stok hampir habis"** dashboard & **Total stok**.
  - **Tidak** bisa dimutasi ke gudang (muncul pesan penolakan).
  - **Bisa** diinput pada **transaksi belanja**.
- Matikan lagi toggle-nya → perilaku kembali normal.

> Catatan kompatibilitas: barang yang (masih) punya **kategori "pasar"** otomatis
> diperlakukan sebagai barang pasar, agar data lama tetap konsisten.

---

## LANGKAH 10 — Verifikasi akhir

- [ ] `https://handayani.my.id/admin/login` → **login langsung tetap jalan**
- [ ] `https://handayani.my.id/asisten-ai` → **chat AI jalan** (dengan `AI_ENABLED=true`)
- [ ] Dashboard, Stok, Mutasi, Belanja → **normal** & tidak error
- [ ] Barang Pasar: **tidak** muncul di stok/SAW/dashboard; **bisa** di belanja
- [ ] Voice jalan di **Chrome/Edge** lewat **HTTPS**
- [ ] `php artisan config:cache route:cache view:cache` sudah dijalankan
- [ ] Hapus file backup sensitif setelah yakin:
      `rm ~/.env.bak-*` dan `rm ~/logistik-backup-*.sql`

---

## Rollback (bila ada masalah)

### A. Matikan AI saja (paling cepat, tanpa ubah kode)
```bash
cd ~/logistik-handayani
nano .env                     # set AI_ENABLED=false
php artisan config:clear && php artisan config:cache
```
Menu Asisten AI langsung hilang; fitur lama tetap jalan.

### B. Kembalikan kode ke commit sebelumnya
```bash
cd ~/logistik-handayani
git log --oneline -6          # catat commit AI terakhir
git checkout d23a0c9          # commit terakhir sebelum fitur AI
php artisan optimize:clear && php artisan config:cache
```

### C. Batalkan migrasi AI (drop tabel/kolom — hanya bila membatalkan fitur)
```bash
cd ~/logistik-handayani
php artisan migrate:rollback --step=3 --force
```
> Ini menghapus tabel `ai_*` dan kolom `barangs.is_barang_pasar`. **Hanya
> jalankan bila memang membatalkan fitur.** Data aplikasi lain tidak tersentuh.

### D. Pulihkan `.env` / database dari backup
```bash
cd ~/logistik-handayani
cp .env.bak-YYYYMMDD-HHMM .env         # sesuaikan nama
mysql -u USER -p NAMA_DB < ~/logistik-backup-YYYYMMDD.sql
php artisan optimize:clear && php artisan config:cache
```

---

## Lampiran A — Uji branch TANPA merge ke `main`

```bash
cd ~/logistik-handayani

# (Langkah 1 & 2 tetap wajib: bersihkan working tree + backup)

git fetch origin
git checkout feature/ai-assistant
git pull origin feature/ai-assistant
```

Lanjutkan dari **Langkah 4**. Setelah puas & yakin, kembali dan merge ke `main`
(Langkah 0 di lokal), lalu `git checkout main && git pull origin main`.

---

## Lampiran B — Catatan penting

1. **Jangan pernah** `git pull` saat ada perubahan lokal (working tree kotor).
2. **Composer tidak ada di PATH?** Pakai `composer.phar`
   (`php ~/composer.phar ...`) — meski untuk deploy ini **tidak wajib install**.
3. **`php artisan`** bisa error di hosting yang memblokir fungsi PHP; catat
   pesannya, lalu coba lewati `config:cache`/`route:cache` bila perlu.
4. **Secret:** jangan pernah commit `.env`/`.env.bak-*`. Sudah di-`.gitignore`.
5. **Keamanan:** setelah semuanya stabil, **rotasi `AI_API_KEY`** (KryptonLab)
   bila pernah terekspos, dan hapus file backup lama.
6. **Biaya:** setiap pesan AI memakai token gateway. Pantau lewat menu
   **Pengaturan/Token AI** (khusus `super_admin`).
7. **PWA / cache browser:** setelah deploy, lakukan **hard refresh**
   (Ctrl+F5) atau *Clear site data* agar aset `ai-assistant-*.js` terbaru termuat.
8. **Menu khusus super_admin** yang muncul walau AI OFF: **Pengaturan AI**,
   **Token AI**, **Pengetahuan AI**. Ini tidak mengubah data apa pun.

---

*Dokumen ini untuk deploy fitur **Asisten AI + Barang Pasar** di **Logistik**.
Panduan deploy **SSO** ada di `docs/PANDUAN_DEPLOY_SSO_SERVER.md`.*
