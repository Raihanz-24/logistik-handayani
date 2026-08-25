<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BarangLokasiResource;
use App\Filament\Resources\BarangResource;
use App\Filament\Resources\KategoriBarangResource;
use App\Filament\Resources\LokasiResource;
use App\Filament\Resources\MutasiRakResource;
use App\Filament\Resources\MutasiResource;
use App\Filament\Resources\SupplierResource;
use App\Filament\Resources\UserResource;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class UserGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Bantuan';

    protected static ?string $navigationLabel = 'Panduan Penggunaan';

    protected static ?int $navigationSort = 999999;

    protected static ?string $slug = 'panduan-penggunaan';

    protected static string $view = 'filament.pages.user-guide';

    protected ?string $heading = 'Panduan Penggunaan';

    protected ?string $subheading = 'Langkah cepat mengoperasikan Logistik Taman Air Handayani Paiton.';

    protected ?string $maxContentWidth = 'full';

    /**
     * @return array<string, string>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'warehouse-dashboard-body warehouse-guide-body',
        ];
    }

    /**
     * @return array<int, array{label: string, description: string, icon: string, url: string, tone: string}>
     */
    public function getQuickActions(): array
    {
        return [
            [
                'label' => 'Buka Dashboard',
                'description' => 'Lihat ringkasan stok, peringatan, dan rekomendasi restock.',
                'icon' => 'heroicon-o-chart-bar-square',
                'url' => Filament::getUrl(),
                'tone' => 'amber',
            ],
            [
                'label' => 'Kelola Barang',
                'description' => 'Tambah dan rapikan master barang sebelum stok dipakai.',
                'icon' => 'heroicon-o-cube',
                'url' => BarangResource::getUrl(),
                'tone' => 'blue',
            ],
            [
                'label' => 'Cek Stok Barang',
                'description' => 'Pantau stok yang terbentuk dari mutasi masuk yang sudah di-approve.',
                'icon' => 'heroicon-o-building-storefront',
                'url' => BarangLokasiResource::getUrl(),
                'tone' => 'green',
            ],
            [
                'label' => 'Input Mutasi',
                'description' => 'Catat barang masuk, keluar, dan transfer stok.',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => MutasiResource::getUrl('create'),
                'tone' => 'cyan',
            ],
            [
                'label' => 'Pindah Rak Barang',
                'description' => 'Pindahkan seluruh stok barang ke rak lain dalam gudang yang sama.',
                'icon' => 'heroicon-o-rectangle-group',
                'url' => MutasiRakResource::getUrl('create'),
                'tone' => 'amber',
            ],
        ];
    }

    /**
     * @return array<int, array{step: string, title: string, description: string, points: array<int, string>, icon: string}>
     */
    public function getWorkflowSteps(): array
    {
        return [
            [
                'step' => '01',
                'title' => 'Siapkan master data',
                'description' => 'Mulai dari data dasar agar transaksi mutasi tidak membingungkan.',
                'points' => [
                    'Buat kategori barang agar pencarian lebih rapi.',
                    'Tambahkan nama dan satuan barang; kode BRG dibuat otomatis oleh sistem.',
                    'Tambahkan supplier dari menu Supplier, atau buat langsung saat mengisi mutasi masuk.',
                    'Saat membuat gudang, tentukan penggunaan rak beserta jumlah tingkatnya bila diperlukan.',
                ],
                'icon' => 'heroicon-o-squares-2x2',
            ],
            [
                'step' => '02',
                'title' => 'Input mutasi masuk pengadaan',
                'description' => 'Stok gudang bertambah dari mutasi masuk barang baru, bukan diisi langsung dari menu stok.',
                'points' => [
                    'Buka menu Mutasi dan pilih jenis mutasi masuk.',
                    'Pilih supplier, barang, gudang tujuan, kondisi, jumlah, dan rak tujuan bila gudang menggunakan rak.',
                    'Simpan transaksi agar masuk ke daftar mutasi pending untuk divalidasi.',
                ],
                'icon' => 'heroicon-o-arrow-down-tray',
            ],
            [
                'step' => '03',
                'title' => 'Approve agar stok terbentuk',
                'description' => 'Mutasi masuk perlu disetujui agar stok gudang benar-benar bertambah.',
                'points' => [
                    'Cek barang, gudang tujuan, jumlah, dan referensi pengadaan.',
                    'Setelah approve, sistem memperbarui stok barang di gudang terkait.',
                    'Jika data belum benar, batalkan mutasi dan input ulang dengan data yang tepat.',
                ],
                'icon' => 'heroicon-o-check-badge',
            ],
            [
                'step' => '04',
                'title' => 'Pantau stok barang',
                'description' => 'Menu Stok Barang digunakan untuk melihat posisi stok setelah mutasi disetujui.',
                'points' => [
                    'Cek stok total, kondisi Baik/Rusak, dan rak tetap barang.',
                    'Gunakan dashboard untuk memantau barang yang mulai menipis.',
                    'Pastikan stok fisik gudang sesuai dengan stok yang tercatat di sistem.',
                ],
                'icon' => 'heroicon-o-archive-box',
            ],
            [
                'step' => '05',
                'title' => 'Input mutasi keluar atau transfer',
                'description' => 'Barang keluar dan transfer gudang dicatat setelah stok tersedia.',
                'points' => [
                    'Pilih jenis mutasi keluar untuk pemakaian atau transfer stok.',
                    'Pilih gudang asal lalu cari barang; rak asal diambil otomatis oleh sistem.',
                    'Pilih kondisi asal Baik atau Rusak; kondisi barang tetap sama saat dipindahkan.',
                    'Gunakan mutasi Perubahan Kondisi; sistem otomatis mengubah Baik menjadi Rusak atau Rusak menjadi Baik.',
                    'Sistem menolak mutasi bila stok kondisi tidak cukup atau rak tujuan tidak valid.',
                ],
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            [
                'step' => '06',
                'title' => 'Pindahkan posisi antar-rak',
                'description' => 'Gunakan mutasi antar-rak bila seluruh stok barang perlu ditempatkan di rak lain pada gudang yang sama.',
                'points' => [
                    'Pilih gudang dan barang; rak asal serta seluruh jumlah stok ditampilkan otomatis.',
                    'Pilih rak tujuan aktif yang berbeda dari rak asal, lalu simpan untuk meminta persetujuan.',
                    'Mutasi stok lain untuk barang dan gudang yang sama dikunci sampai permintaan disetujui atau dibatalkan.',
                    'Gunakan menu Mutasi biasa untuk perpindahan barang antar-gudang.',
                ],
                'icon' => 'heroicon-o-rectangle-group',
            ],
            [
                'step' => '07',
                'title' => 'Monitoring dan export laporan',
                'description' => 'Dashboard dan export Excel membantu rekap, audit, dan pelaporan berkala.',
                'points' => [
                    'Gunakan filter periode untuk melihat statistik sesuai tanggal.',
                    'Perhatikan peringatan stok rendah dan rekomendasi restock.',
                    'Atur filter mutasi sebelum export Excel.',
                    'Gunakan pencarian jika hanya butuh barang tertentu.',
                ],
                'icon' => 'heroicon-o-presentation-chart-line',
            ],
        ];
    }

    /**
     * @return array<int, array{title: string, description: string, icon: string, url: string}>
     */
    public function getModuleCards(): array
    {
        return [
            [
                'title' => 'Kategori Barang',
                'description' => 'Kelompokkan barang agar data master mudah dibaca dan dicari.',
                'icon' => 'heroicon-o-tag',
                'url' => KategoriBarangResource::getUrl(),
            ],
            [
                'title' => 'Barang',
                'description' => 'Pusat data barang: nama, kode, satuan, dan deskripsi.',
                'icon' => 'heroicon-o-cube',
                'url' => BarangResource::getUrl(),
            ],
            [
                'title' => 'Lokasi',
                'description' => 'Kelola gudang, rak bertingkat, dan lokasi tujuan pemakaian barang.',
                'icon' => 'heroicon-o-map-pin',
                'url' => LokasiResource::getUrl(),
            ],
            [
                'title' => 'Supplier',
                'description' => 'Kelola pemasok yang dapat dipilih pada setiap mutasi barang masuk.',
                'icon' => 'heroicon-o-truck',
                'url' => SupplierResource::getUrl(),
            ],
            [
                'title' => 'Stok Barang',
                'description' => 'Lihat stok per gudang, rak tetap, serta kondisi Baik, Rusak, dan Hilang.',
                'icon' => 'heroicon-o-building-storefront',
                'url' => BarangLokasiResource::getUrl(),
            ],
            [
                'title' => 'Mutasi',
                'description' => 'Catat barang masuk, transfer, pemakaian, dan perubahan kondisi melalui approval.',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => MutasiResource::getUrl(),
            ],
            [
                'title' => 'Mutasi Antar Rak',
                'description' => 'Pindahkan seluruh stok barang antar-rak dalam satu gudang melalui approval.',
                'icon' => 'heroicon-o-rectangle-group',
                'url' => MutasiRakResource::getUrl(),
            ],
            [
                'title' => 'User',
                'description' => 'Kelola akun pengguna dan akses yang tersedia.',
                'icon' => 'heroicon-o-users',
                'url' => UserResource::getUrl(),
            ],
        ];
    }
}
