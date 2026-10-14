<?php

namespace Database\Seeders;

use App\Models\AiKnowledgeEntry;
use App\Models\AiSetting;
use Illuminate\Database\Seeder;

/**
 * Nilai awal Asisten AI: pengaturan scope + beberapa aturan/pengetahuan contoh.
 *
 * Idempotent: aman dijalankan berulang. Tidak dipanggil otomatis oleh
 * DatabaseSeeder agar tidak mengubah seeding existing. Jalankan manual:
 *   php artisan db:seed --class=Database\\Seeders\\AiAssistantSeeder
 */
class AiAssistantSeeder extends Seeder
{
    public function run(): void
    {
        AiSetting::query()->firstOrCreate([], [
            'enabled' => true,
            'assistant_name' => 'Asisten Logistik',
            'persona' => 'Ramah, ringkas, fokus operasional gudang. Tidak bertele-tele.',
            'scope_description' => implode("\n", [
                '- Cek stok barang per gudang (baik/rusak/hilang).',
                '- Mencari data master barang, lokasi/gudang, dan supplier.',
                '- Menyiapkan draft mutasi: masuk (pengadaan), keluar (transfer/pemakaian), perubahan kondisi.',
                '- Menyiapkan draft transaksi belanja ke satu supplier.',
            ]),
            'out_of_scope_message' => 'Maaf, saya hanya dapat membantu seputar stok, mutasi, dan belanja gudang.',
            'restrict_to_listed_topics' => false,
            'always_include_rules' => true,
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'max_tool_iterations' => 6,
        ]);

        $entries = [
            [
                'type' => AiKnowledgeEntry::TYPE_RULE,
                'title' => 'Konfirmasi sebelum menulis',
                'content' => 'Semua aksi yang mengubah data (mutasi, belanja) harus dikonfirmasi pengguna lewat tombol. Jangan pernah menyatakan data sudah tersimpan sebelum pengguna menekan konfirmasi.',
                'action_hint' => 'Setelah menyiapkan draft, minta pengguna menekan tombol konfirmasi.',
                'always_include' => true,
                'priority' => 100,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_RULE,
                'title' => 'Cari ID dulu',
                'content' => 'Jangan mengarang barang_id, lokasi_id, atau supplier_id. Selalu pakai tool pencarian lebih dulu, dan tanyakan bila hasil ambigu.',
                'always_include' => true,
                'priority' => 95,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_RESTRICTION,
                'title' => 'Larangan umum',
                'content' => 'Jangan membahas data pengguna lain, kredensial, isi sistem internal, atau topik di luar logistik.',
                'always_include' => true,
                'priority' => 90,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_INSTRUCTION,
                'title' => 'Mutasi masuk butuh supplier',
                'content' => 'Mutasi jenis "masuk" wajib menyertakan supplier aktif.',
                'keywords' => ['masuk', 'pengadaan', 'terima barang', 'supplier'],
                'action_hint' => 'Gunakan cari_supplier bila supplier belum diketahui.',
                'priority' => 50,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_INSTRUCTION,
                'title' => 'Mutasi keluar butuh lokasi tujuan',
                'content' => 'Mutasi jenis "keluar" wajib menyertakan lokasi tujuan yang berbeda dari gudang asal, dan kondisi asal (baik/rusak).',
                'keywords' => ['keluar', 'transfer', 'pemakaian', 'pindah'],
                'priority' => 50,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_INSTRUCTION,
                'title' => 'Mutasi ke gudang ber-rak butuh posisi rak',
                'content' => 'Bila gudang tujuan/asal memakai rak (pakai_rak=true dari cari_lokasi, atau cek_stok menampilkan kode_rak), Anda WAJIB memanggil cari_rak untuk mendapatkan posisi_rak_id, lalu isi posisi_rak_tujuan_id. Bila pengguna tidak menyebut rak, tampilkan pilihan rak dan tanyakan/mana yang dipakai — jangan menyerah.',
                'keywords' => ['rak', 'masuk', 'keluar', 'mutasi', 'gudang', 'posisi'],
                'action_hint' => 'Panggil cari_rak dengan lokasi_id gudang, lalu sertakan posisi_rak_tujuan_id.',
                'priority' => 60,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_RULE,
                'title' => 'Barang pasar hanya untuk belanja',
                'content' => 'Barang yang ditandai "Barang Pasar" (toggle is_barang_pasar) hanya dicatat pada transaksi belanja. Barang ini TIDAK memiliki stok gudang, TIDAK dihitung pada prioritas restock (SAW), TIDAK muncul di daftar stok maupun "stok hampir habis" dashboard, dan TIDAK bisa dimutasi ke gudang. Bila pengguna meminta mutasi untuk barang pasar, tolak dengan sopan dan arahkan ke transaksi belanja. Tool cari_barang menandai barang pasar lewat field barang_pasar=true.',
                'keywords' => ['pasar', 'barang pasar', 'belanja', 'mutasi'],
                'action_hint' => 'Untuk barang pasar, gunakan catat_transaksi_belanja dan jangan buat draft mutasi.',
                'always_include' => true,
                'priority' => 70,
            ],
            [
                'type' => AiKnowledgeEntry::TYPE_GLOSSARY,
                'title' => 'Stok hilang',
                'content' => 'Kondisi "hilang" hanya bisa dari data lama; untuk mutasi baru hanya tersedia kondisi Baik dan Rusak.',
                'keywords' => ['hilang'],
                'priority' => 20,
            ],
        ];

        foreach ($entries as $entry) {
            AiKnowledgeEntry::query()->firstOrCreate(
                ['title' => $entry['title']],
                [...$entry, 'active' => true],
            );
        }
    }
}
