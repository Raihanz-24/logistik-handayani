<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BarangLokasiResource;
use App\Filament\Resources\BarangResource;
use App\Filament\Resources\KategoriBarangResource;
use App\Filament\Resources\LokasiResource;
use App\Filament\Resources\MutasiResource;
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

    protected ?string $subheading = 'Langkah cepat mengoperasikan Warehouse Monitoring PT ISS.';

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
                    'Tambahkan data barang lengkap dengan kode dan satuan.',
                    'Pastikan lokasi gudang tersedia sebelum transaksi pengadaan dicatat.',
                ],
                'icon' => 'heroicon-o-squares-2x2',
            ],
            [
                'step' => '02',
                'title' => 'Input mutasi masuk pengadaan',
                'description' => 'Stok gudang bertambah dari mutasi masuk barang baru, bukan diisi langsung dari menu stok.',
                'points' => [
                    'Buka menu Mutasi dan pilih jenis mutasi masuk.',
                    'Pilih barang, gudang tujuan, tanggal, jumlah, dan nomor referensi pengadaan.',
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
                    'Cek stok per barang dan gudang.',
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
                    'Pilih gudang asal, barang, jumlah, dan lokasi tujuan.',
                    'Sistem menolak mutasi keluar bila stok gudang asal tidak mencukupi.',
                ],
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            [
                'step' => '06',
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
                'description' => 'Kelola gudang dan lokasi tujuan pemakaian barang.',
                'icon' => 'heroicon-o-map-pin',
                'url' => LokasiResource::getUrl(),
            ],
            [
                'title' => 'Stok Barang',
                'description' => 'Lihat stok barang per gudang setelah mutasi masuk/keluar disetujui.',
                'icon' => 'heroicon-o-building-storefront',
                'url' => BarangLokasiResource::getUrl(),
            ],
            [
                'title' => 'Mutasi',
                'description' => 'Catat pergerakan barang masuk, keluar, transfer, approve, dan export.',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => MutasiResource::getUrl(),
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
