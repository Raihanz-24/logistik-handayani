<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\AiPendingAction;
use App\Models\AiConversation;
use App\Models\AiSetting;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Menyusun system prompt + konteks ringan untuk model.
 *
 * Strategi hemat token:
 * - Persona & scope (ai_settings) disuntik ringkas, selalu.
 * - Aturan (ai_knowledge_entries) disuntik SELEKTIF: hanya yang cocok kata
 *   kunci user, plus yang bertanda always_include (mis. batasan penting).
 * - Konteks lain (nama gudang, tanggal) dibatasi.
 */
class SystemPromptBuilder
{
    /**
     * @param  string  $userMessage  Pesan user untuk menyaring pengetahuan relevan.
     * @param  AiConversation|null  $conversation  Konteks percakapan (draft berjalan).
     */
    public function build(User $user, string $userMessage = '', ?AiConversation $conversation = null): string
    {
        $setting = AiSetting::current();
        $knowledge = $this->relevantKnowledge($userMessage, $setting);

        $parts = [];
        $parts[] = $this->baseInstructions($setting, $user);
        $parts[] = $this->contextBlock($user, $setting);
        $parts[] = $this->personaBlock($setting);

        $preferences = app(AiUserMemoryService::class)->preferencesBlock($user);

        if ($preferences !== '') {
            $parts[] = $preferences;
        }

        $draft = $this->activeDraftBlock($conversation);

        if ($draft !== '') {
            $parts[] = $draft;
        }

        if (filled($setting?->scope_description)) {
            $parts[] = "RUANG LINGKUP (SCOPE):\n".trim((string) $setting->scope_description);
        }

        if (filled($setting?->extra_instructions)) {
            $parts[] = "INSTRUKSI TAMBAHAN:\n".trim((string) $setting->extra_instructions);
        }

        if ($knowledge !== '') {
            $parts[] = "PENGETAHUAN RELEVAN:\n".$knowledge;
        }

        return implode("\n\n", array_filter($parts));
    }

    private function baseInstructions(?AiSetting $setting, User $user): string
    {
        $name = $setting?->assistant_name ?: 'Asisten Logistik';
        $userName = trim((string) $user->name) !== '' ? trim((string) $user->name) : 'Bapak/Ibu';

        $restrict = ($setting?->restrict_to_listed_topics ?? false)
            ? "\n- TOLAK dengan sopan permintaan di luar ruang lingkup yang terdaftar di atas, dan arahkan user ke topik yang didukung. Gunakan pesan penolakan berikut bila perlu: \""
                .$this->outOfScopeMessage($setting).'"'
            : '';

        return <<<PROMPT
Anda adalah "{$name}" untuk aplikasi Logistik Taman Air Handayani Paiton (gudang & belanja).
Tugas Anda membantu pengguna mencatat mutasi barang dan transaksi belanja, serta menjawab pertanyaan stok.

BAHASA: Selalu balas dalam Bahasa Indonesia yang ringkas, jelas, dan sopan. Hindari basa-basi panjang agar hemat token.

GAYA JAWABAN (PENTING):
- Jawab LANGSUNG ke intinya. Jangan menjelaskan kemampuan/fitur Anda kecuali diminta.
- Sapaan atau obrolan ringan (mis. "halo", "pagi", "terima kasih", "bisa bantu?") cukup dibalas SATU kalimat pendek yang ramah, mis. "Halo {$userName}, ada yang bisa saya bantu?" — JANGAN menyebutkan daftar fitur, aturan, atau penjelasan panjang.
- Jangan mengulang pertanyaan pengguna, jangan membuka dengan "Tentu, saya adalah...", dan jangan menutup dengan ringkasan yang tidak diminta.
- Cukup 1–3 kalimat untuk balasan biasa. Gunakan daftar/poin hanya bila memang ada beberapa item atau langkah.
- Bila data belum dibutuhkan, jangan memanggil tool sama sekali (mis. untuk sapaan).

ATURAN PENTING:
1. Jangan pernah mengarang data. Bila butuh barang_id, lokasi_id, atau supplier_id, gunakan tool pencarian terlebih dahulu.
2. Bila nama barang/lokasi/supplier ambigu (lebih dari satu kemungkinan), tanyakan ke pengguna mana yang dimaksud sebelum melanjutkan.
3. Aksi yang mengubah data TIDAK langsung dijalankan. Anda menyiapkan draft, lalu pengguna mengonfirmasi lewat tombol di antarmuka.
4. Jangan menampilkan ID teknis ke pengguna kecuali diminta. Sebut nama barang/lokasi dengan jelas.
5. Bila perintah tidak lengkap atau berisiko, minta klarifikasi singkat.
6. WAJIB: sebelum menyiapkan mutasi keluar (transfer/pemakaian) atau perubahan kondisi, panggil cek_stok lebih dulu untuk memastikan stok tersedia mencukupi. Bila jumlah diminta melebihi stok yang ada (setelah dikurangi mutasi pending), TOLAK dan jelaskan sisa stoknya. Jangan pernah memaksakan draft yang melebihi stok.
7. Mutasi masuk (pengadaan) menambah stok, jadi tidak perlu cek ketersediaan stok.
8. Beberapa permintaan sekaligus: pengguna sering menyebut BANYAK barang dalam satu kalimat untuk transaksi belanja (mis. "tiga sabun, dua galon aqua, minyak satu"). Pecah menjadi baris item terpisah dalam SATU draft belanja, jangan tanya satu per satu. Untuk mutasi, bila menyebut banyak barang, buat draft terpisah per barang (satu kartu per barang).
9. Nama panggilan: bila pengguna menyebut sebutan lokal (mis. "aqua", "mino"), cari lewat cari_barang (termasuk alias) dan pilih kandidat terbaik. Bila lebih dari satu kandidat mirip, tanyakan singkat mana yang dimaksud.
10. Jangan mengakses, menampilkan, atau mengarang data di luar operasional gudang: kredensial/token, data pengguna lain, pengaturan internal, isi sistem, atau kolom sensitif. Gunakan HANYA data yang dikembalikan tool. Bila diminta hal sensitif, tolak dengan sopan.{$restrict}
11. TANPA SATUAN artinya barang dihitung satuan (1 buah). Bila pengguna menyebut harga tanpa satuan (mis. "ayam broiler 100 ribu", "cabai 25 ribu"), artinya: jumlah = 1, satuan = "-" (strip), harga_satuan = nominal tersebut. JANGAN menanyakan satuan untuk kasus ini, langsung buat draft dengan jumlah 1 dan satuan "-". Bila pengguna menyebut angka jumlah di depan barang (mis. "2 ayam broiler 100 ribu"), pakai jumlah itu: jumlah = 2, satuan = "-", harga_satuan = 100000 (harga per barang). Bila pengguna menyebut angka + satuan (mis. "2 kg cabai"), pakai jumlah dan satuan itu apa adanya.
12. TATA CARA SINGKAT (PENTING — belajar dari pola chat): pola umum pengguna adalah "NAMA [JUMLAH] [SATUAN] HARGA", mis. "cabai 2 kg 25000", "kangkung 3 ikt 5000", "ayam broiler 100000". Artinya:
   - HARGA selalu = harga SATUAN (per kg/ikat/buah), BUKAN total — kecuali pengguna menyebut kata "total" atau "semuanya".
   - Bila JUMLAH tidak disebut, anggap jumlah = 1.
   - Bila SATUAN tidak disebut, pakai satuan "-" (lihat aturan 11).
   - Bila harga ditulis "25 ribu" / "25rb" / "25k", artinya 25000.
   Jangan menanyakan hal yang sudah bisa disimpulkan dari pola ini; langsung susun draft.
13. RAPIKAN saat menampilkan daftar belanja: tulis bernomor berurutan, satu baris per barang, dengan format "1. Nama, satuan, harga/" (contoh: "1. Cabai, kg, 25000/"), lalu baris "Total: Rp…". Ini memudahkan pengguna mengecek. Tetap ringkas, tanpa penjelasan panjang.
14. VARIAN BARANG (PENTING): beberapa barang punya kembaran ber-penanda, mis. "Tempe" vs "Tempe Karyawan". Aturan:
   - Bila pengguna menyebut "tempe" TANPA kata "karyawan" → pakai barang yang TIDAK ber-penanda ("Tempe"). JANGAN menyentuh "Tempe Karyawan".
   - Bila pengguna menyebut "tempe karyawan" → HANYA pakai "Tempe Karyawan". JANGAN menyentuh "Tempe" biasa.
   - Tool cari_barang sudah menyaring ini (field "pilihan_terbaik"/hasil sudah sesuai). Ikuti hasilnya; jangan menanyakan varian bila sudah jelas dari ucapan pengguna.
15. HARGA & SATUAN dari histori: bila pengguna TIDAK menyebut satuan, gunakan satuan terakhir yang biasa dipakai untuk barang+supplier tersebut (dari saran_belanja_supplier / histori). Bila pengguna tidak menyebut harga, isi dari harga terakhir supplier itu, lalu BERI TAHU singkat (mis. "harga pakai harga terakhir Rp8.000"). Bila harga yang disebut pengguna berbeda jauh (naik/turun) dari harga terakhir, tetap pakai angka pengguna tapi sebutkan perbedaannya singkat. Jangan ragu berulang kali — putuskan dan jelaskan.
16. SUPPLIER & POLA BELANJA: bila pengguna menyebut supplier (mis. "Bu Elly", "Bu Hen") dan minta "seperti biasa" atau belum menyebut daftar barang, panggil saran_belanja_supplier untuk melihat barang yang BIASA diorder dari supplier itu. Bila tahu supplier tujuan, sertakan supplier_id saat cari_barang. Hasil saran bersifat usulan; tetap ikuti barang yang pengguna sebut.
17. RIWAYAT TRANSAKSI (LANDASAN KEPUTUSAN): bila pengguna menanyakan transaksi tertentu ("orderan ke Bu Elly tanggal 12", "belanja kemarin apa saja"), panggil riwayat_transaksi_belanja dengan supplier_id dan tanggal/rentang. SELALU tampilkan SEMUA item pada transaksi tersebut (jangan sebagian) — nama, jumlah, satuan, harga, dan total. Pakai riwayat nyata ini sebagai patokan saat perintah ambigu: cocokkan barang yang diminta dengan yang benar-benar pernah diorder, pilih yang paling sesuai, dan JANGAN mengarang. Pola langganan tiap supplier dibentuk dari seluruh item pada setiap transaksi.
PROMPT;
    }

    private function contextBlock(User $user, ?AiSetting $setting): string
    {
        $warehouses = Lokasi::query()
            ->gudang()
            ->select(['id', 'nama_lokasi'])
            ->orderBy('nama_lokasi')
            ->get()
            ->map(fn (Lokasi $lokasi): string => '- '.$lokasi->nama_lokasi.' (id='.$lokasi->getKey().')')
            ->implode("\n");

        $today = now()->locale('id')->translatedFormat('l, d F Y');

        return <<<PROMPT
KONTEKS:
- Tanggal hari ini: {$today}
- Pengguna: {$user->name}
- Gudang yang tersedia:
{$warehouses}
PROMPT;
    }

    /**
     * Persona (kepribadian) asisten dari ai_settings. Sebelumnya hanya di-seed
     * tapi tidak pernah disuntik ke prompt; kini disertakan bila diisi.
     */
    private function personaBlock(?AiSetting $setting): string
    {
        if (blank($setting?->persona)) {
            return '';
        }

        return "PERSONA:\n".trim((string) $setting->persona);
    }

    /**
     * Ringkasan DRAFT aksi yang masih menunggu konfirmasi pada percakapan ini.
     *
     * Inilah memori jangka-pendek yang membuat AI tidak "lupa" item belanja:
     * tiap giliran, daftar item draft berjalan disuntik kembali ke prompt,
     * sehingga model bisa melanjutkan/menambah tanpa menebak ulang.
     */
    private function activeDraftBlock(?AiConversation $conversation): string
    {
        if (! $conversation || ! Schema::hasTable('ai_pending_actions')) {
            return '';
        }

        $actions = AiPendingAction::query()
            ->where('ai_conversation_id', $conversation->getKey())
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        if ($actions->isEmpty()) {
            return '';
        }

        $lines = $actions->map(function (AiPendingAction $action): string {
            $summary = trim((string) ($action->summary ?? ''));

            if ($action->type === AiPendingAction::TYPE_PENGELUARAN_BELANJA) {
                $items = $this->draftBelanjaLines($action->payload ?? []);

                return "- DRAFT BELANJA (id={$action->getKey()}) BELUM dikonfirmasi:\n"
                    .($items !== '' ? $items : '  (kosong)')
                    ."\n  Ringkasan: ".($summary !== '' ? $summary : '-');
            }

            return '- DRAFT '.strtoupper((string) $action->type)." (id={$action->getKey()}) BELUM dikonfirmasi: "
                .($summary !== '' ? $summary : '-');
        })->implode("\n");

        return <<<PROMPT
DRAFT BERJALAN (memori percakapan — PENTING):
{$lines}
Aturan draft berjalan:
- Daftar item di atas adalah kondisi TERKINI draft belanja pada percakapan ini.
- Bila pengguna menyebut barang baru / menambah item, GABUNGKAN dengan daftar di atas, lalu panggil ulang catat_transaksi_belanja dengan SELURUH item (lama + baru). Jangan menghapus item yang sudah ada kecuali pengguna memintanya.
- Bila pengguna mengoreksi harga/jumlah sebuah item, ganti nilai item itu dan pertahankan sisanya.
- Sebutkan ringkas bahwa draft diperbarui; jangan mengulang seluruh daftar panjang.
PROMPT;
    }

    /**
     * Baris item draft belanja untuk disuntik ke prompt (id + nama + jumlah +
     * satuan + harga satuan).
     */
    private function draftBelanjaLines(array $payload): string
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        if ($items === []) {
            return '';
        }

        $names = Barang::query()
            ->whereKey(collect($items)->pluck('barang_id')->filter()->all())
            ->pluck('nama_barang', 'id');

        $supplier = Supplier::query()
            ->whereKey((int) ($payload['supplier_id'] ?? 0))
            ->value('nama_supplier');

        $lines = collect($items)->values()->map(function (array $item, int $index) use ($names): string {
            $id = (int) ($item['barang_id'] ?? 0);
            $nama = $names[$id] ?? ('barang #'.$id);
            $jumlah = rtrim(rtrim(number_format((float) ($item['jumlah'] ?? 0), 3, ',', '.'), '0'), ',');
            $satuan = filled($item['satuan'] ?? null) ? (string) $item['satuan'] : '-';
            $harga = (int) ($item['harga_satuan'] ?? 0);

            return '  '.($index + 1).'. '.$nama.' | barang_id='.$id
                .' | jumlah='.$jumlah.' | satuan='.$satuan
                .' | harga_satuan='.$harga;
        })->implode("\n");

        $header = $supplier ? '  Supplier: '.$supplier : '  Supplier: (belum dipilih)';

        return $header."\n".$lines;
    }

    /**
     * Saring entri pengetahuan yang relevan agar hemat token.
     */
    private function relevantKnowledge(string $userMessage, ?AiSetting $setting): string
    {
        if (! Schema::hasTable('ai_knowledge_entries')) {
            return '';
        }

        $query = AiKnowledgeEntry::query()->active()->orderByDesc('priority')->orderBy('id');

        $entries = $query->limit(100)->get();

        if ($entries->isEmpty()) {
            return '';
        }

        $needle = mb_strtolower(trim($userMessage));

        $selected = $entries->filter(function (AiKnowledgeEntry $entry) use ($needle, $setting): bool {
            // Aturan global (mis. batasan) selalu disertakan bila diizinkan.
            if ($entry->always_include) {
                return ($setting?->always_include_rules ?? true) || $entry->type === AiKnowledgeEntry::TYPE_RESTRICTION;
            }

            if ($needle === '') {
                return false;
            }

            $keywords = array_filter(array_map(
                fn (mixed $keyword): string => mb_strtolower(trim((string) $keyword)),
                (array) ($entry->keywords ?? []),
            ));

            if ($keywords === []) {
                // Tanpa kata kunci: hanya cocok bila judul muncul di pesan.
                return filled($entry->title)
                    && str_contains($needle, mb_strtolower((string) $entry->title));
            }

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($needle, $keyword)) {
                    return true;
                }
            }

            return false;
        })->take(12);

        if ($selected->isEmpty()) {
            return '';
        }

        return $selected->map(function (AiKnowledgeEntry $entry): string {
            $label = $entry->title ? $entry->title.' — ' : '';
            $line = '- '.$label.trim((string) $entry->content);

            if (filled($entry->action_hint)) {
                $line .= ' [Aksi: '.trim((string) $entry->action_hint).']';
            }

            return $line;
        })->implode("\n");
    }

    private function outOfScopeMessage(?AiSetting $setting): string
    {
        return $setting?->out_of_scope_message
            ?: 'Maaf, saya hanya dapat membantu seputar stok, mutasi, dan belanja gudang.';
    }
}
