# Kamus Data Sistem Warehouse Monitoring PT ISS

Kamus data ini disusun berdasarkan ERD utama sistem Warehouse Monitoring PT ISS dan struktur migration project. Fokus tabel mengikuti ERD yang dipakai: data user, role-permission Spatie, master barang, kategori, lokasi, stok barang per lokasi, dan mutasi barang.

## 1. Tabel `users`

Fungsi: menyimpan data akun pengguna sistem.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik user. |
| `name` | varchar | - | Tidak | Nama pengguna. |
| `email` | varchar | UK | Tidak | Email login pengguna. |
| `email_verified_at` | timestamp | - | Ya | Waktu verifikasi email. |
| `password` | varchar | - | Tidak | Password user dalam bentuk hash. |
| `remember_token` | varchar | - | Ya | Token fitur remember me. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

## 2. Tabel `roles`

Fungsi: menyimpan daftar role pengguna dari package Spatie Laravel Permission.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik role. |
| `name` | varchar | UK | Tidak | Nama role, contoh `admin` atau `super_admin`. |
| `guard_name` | varchar | UK | Tidak | Guard autentikasi, umumnya `web`. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

Catatan: kombinasi `name` dan `guard_name` bersifat unique.

## 3. Tabel `permissions`

Fungsi: menyimpan daftar hak akses/permission dari package Spatie Laravel Permission.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik permission. |
| `name` | varchar | UK | Tidak | Nama permission, contoh akses resource, page, atau widget. |
| `guard_name` | varchar | UK | Tidak | Guard autentikasi, umumnya `web`. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

Catatan: kombinasi `name` dan `guard_name` bersifat unique.

## 4. Tabel `model_has_roles`

Fungsi: tabel pivot Spatie untuk menghubungkan user dengan role.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `role_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `roles.id`. |
| `model_type` | varchar | PK | Tidak | Nama class model pemilik role, pada sistem ini `App\Models\User`. |
| `model_id` | bigint unsigned | PK | Tidak | ID model pemilik role, secara logis mengacu ke `users.id`. |

Catatan: tabel ini bersifat polymorphic, sehingga tidak memakai FK langsung ke `users.id`.

## 5. Tabel `role_has_permissions`

Fungsi: tabel pivot Spatie untuk menghubungkan role dengan permission.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `permission_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `permissions.id`. |
| `role_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `roles.id`. |

## 6. Tabel `model_has_permissions`

Fungsi: tabel pivot Spatie untuk memberikan permission langsung ke user tanpa melalui role.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `permission_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `permissions.id`. |
| `model_type` | varchar | PK | Tidak | Nama class model pemilik permission, pada sistem ini `App\Models\User`. |
| `model_id` | bigint unsigned | PK | Tidak | ID model pemilik permission, secara logis mengacu ke `users.id`. |

Catatan: tabel ini bersifat polymorphic, sehingga tidak memakai FK langsung ke `users.id`.

## 7. Tabel `kategori_barangs`

Fungsi: menyimpan data kategori barang.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik kategori barang. |
| `nama` | varchar | UK | Tidak | Nama kategori barang. |
| `slug` | varchar | UK | Tidak | Slug kategori barang. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

## 8. Tabel `barang_kategori_barang`

Fungsi: tabel pivot untuk menghubungkan barang dengan kategori barang.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `kategori_barang_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `kategori_barangs.id`. |
| `barang_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `barangs.id`. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

Catatan: tabel ini digunakan agar satu barang dapat memiliki lebih dari satu kategori, dan satu kategori dapat digunakan oleh banyak barang.

## 9. Tabel `barangs`

Fungsi: menyimpan data master barang.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik barang. |
| `nama_barang` | varchar | - | Tidak | Nama barang. |
| `kode_barang` | varchar | UK | Tidak | Kode unik barang. |
| `kategori_barang_id` | bigint unsigned | FK | Ya | Mengacu ke `kategori_barangs.id`, nullable. |
| `kategori` | varchar | - | Ya | Kolom legacy, dibuat nullable untuk kompatibilitas data lama. |
| `satuan` | varchar | - | Tidak | Satuan barang, contoh pcs, unit, dus. |
| `deskripsi` | text | - | Ya | Deskripsi barang. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

Catatan: pada ERD yang digunakan, relasi kategori utama ditampilkan melalui pivot `barang_kategori_barang`.

## 10. Tabel `lokasis`

Fungsi: menyimpan data lokasi/gudang.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik lokasi. |
| `kode_lokasi` | varchar | UK | Tidak | Kode unik lokasi. |
| `nama_lokasi` | varchar | - | Tidak | Nama lokasi/gudang. |
| `jenis_lokasi` | varchar(30) | Index | Tidak | Jenis lokasi, contoh `gudang` atau `lokasi_pemakaian`. |
| `alamat` | text | - | Ya | Alamat lokasi. |
| `keterangan` | varchar | - | Ya | Keterangan tambahan lokasi. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

## 11. Tabel `barang_lokasi`

Fungsi: menyimpan stok aktif barang pada setiap lokasi/gudang.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `barang_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `barangs.id`. |
| `lokasi_id` | bigint unsigned | PK, FK | Tidak | Mengacu ke `lokasis.id`. |
| `stok` | integer | - | Tidak | Jumlah stok barang pada lokasi tertentu. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

Catatan: `barang_lokasi` adalah pivot table beratribut atau associative entity, karena menghubungkan `barangs` dan `lokasis` sekaligus menyimpan atribut `stok`.

## 12. Tabel `mutasis`

Fungsi: menyimpan histori mutasi barang masuk dan keluar.

| Field | Tipe Data | Key | Null | Keterangan |
|---|---|---|---|---|
| `id` | bigint unsigned | PK | Tidak | ID unik mutasi. |
| `tanggal` | date | - | Tidak | Tanggal mutasi barang. |
| `jenis_mutasi` | enum | - | Tidak | Jenis mutasi: `masuk` atau `keluar`. |
| `jumlah` | integer | - | Tidak | Jumlah barang yang dimutasi. |
| `stok_awal` | integer | - | Ya | Snapshot stok sebelum mutasi disetujui. |
| `stok_akhir` | integer | - | Ya | Snapshot stok setelah mutasi disetujui. |
| `keterangan` | varchar | - | Ya | Keterangan mutasi. |
| `no_ref` | varchar | - | Ya | Nomor referensi mutasi. |
| `status` | enum | - | Tidak | Status mutasi: `pending`, `approved`, atau `cancelled`. |
| `user_id` | bigint unsigned | FK | Tidak | User pelaku/pencatat mutasi, mengacu ke `users.id`. |
| `barang_id` | bigint unsigned | FK | Tidak | Barang yang dimutasi, mengacu ke `barangs.id`. |
| `lokasi_id` | bigint unsigned | FK | Tidak | Lokasi utama/gudang yang terdampak, mengacu ke `lokasis.id`. |
| `lokasi_tujuan_id` | bigint unsigned | FK | Ya | Lokasi tujuan mutasi keluar, mengacu ke `lokasis.id`. |
| `created_by` | bigint unsigned | FK | Ya | User pembuat pengajuan mutasi, mengacu ke `users.id`. |
| `approved_by` | bigint unsigned | FK | Ya | User yang menyetujui mutasi, mengacu ke `users.id`. |
| `approved_at` | timestamp | - | Ya | Waktu mutasi disetujui. |
| `cancelled_by` | bigint unsigned | FK | Ya | User yang membatalkan mutasi, mengacu ke `users.id`. |
| `cancelled_at` | timestamp | - | Ya | Waktu mutasi dibatalkan. |
| `cancel_reason` | varchar(255) | - | Ya | Alasan pembatalan mutasi. |
| `created_at` | timestamp | - | Ya | Waktu data dibuat. |
| `updated_at` | timestamp | - | Ya | Waktu data diperbarui. |

## Ringkasan Relasi Utama

| Relasi | Kardinalitas | Keterangan |
|---|---:|---|
| `roles` - `model_has_roles` | 1:N | Satu role dapat dimiliki banyak model/user. |
| `users` - `model_has_roles` | 1:N secara logis | Satu user dapat memiliki banyak role melalui pivot polymorphic. |
| `roles` - `role_has_permissions` | 1:N | Satu role dapat memiliki banyak permission. |
| `permissions` - `role_has_permissions` | 1:N | Satu permission dapat dimiliki banyak role. |
| `users` - `model_has_permissions` | 1:N secara logis | Satu user dapat memiliki permission langsung. |
| `permissions` - `model_has_permissions` | 1:N | Satu permission dapat diberikan langsung ke banyak model/user. |
| `kategori_barangs` - `barang_kategori_barang` | 1:N | Satu kategori dapat memiliki banyak relasi barang. |
| `barangs` - `barang_kategori_barang` | 1:N | Satu barang dapat memiliki banyak relasi kategori. |
| `barangs` - `barang_lokasi` | 1:N | Satu barang dapat memiliki stok di banyak lokasi. |
| `lokasis` - `barang_lokasi` | 1:N | Satu lokasi dapat menyimpan banyak stok barang. |
| `barang_lokasi` - `mutasis` | 1:N konseptual | Satu kombinasi barang-lokasi dapat memiliki banyak histori mutasi. |
| `barangs` - `mutasis` | 1:N | Satu barang dapat muncul di banyak mutasi. |
| `lokasis` - `mutasis` | 1:N | Satu lokasi dapat terlibat dalam banyak mutasi. |
| `users` - `mutasis` | 1:N | Satu user dapat mencatat, membuat, menyetujui, atau membatalkan banyak mutasi. |

## Catatan

- Stok aktif disimpan pada tabel `barang_lokasi`.
- Tabel `mutasis` menyimpan histori transaksi dan snapshot stok.
- Relasi `barang_lokasi` ke `mutasis` bersifat konseptual melalui pasangan `barang_id` dan `lokasi_id`.
- Tabel Spatie `model_has_roles` dan `model_has_permissions` bersifat polymorphic, sehingga `model_id` secara logis mengacu ke `users.id` ketika `model_type = App\Models\User`.
