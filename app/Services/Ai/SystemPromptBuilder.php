<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\AiSetting;
use App\Models\Lokasi;
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
     */
    public function build(User $user, string $userMessage = ''): string
    {
        $setting = AiSetting::current();
        $knowledge = $this->relevantKnowledge($userMessage, $setting);

        $parts = [];
        $parts[] = $this->baseInstructions($setting);
        $parts[] = $this->contextBlock($user, $setting);

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

    private function baseInstructions(?AiSetting $setting): string
    {
        $name = $setting?->assistant_name ?: 'Asisten Logistik';

        $restrict = ($setting?->restrict_to_listed_topics ?? false)
            ? "\n- TOLAK dengan sopan permintaan di luar ruang lingkup yang terdaftar di atas, dan arahkan user ke topik yang didukung. Gunakan pesan penolakan berikut bila perlu: \""
                .$this->outOfScopeMessage($setting).'"'
            : '';

        return <<<PROMPT
Anda adalah "{$name}" untuk aplikasi Logistik Taman Air Handayani Paiton (gudang & belanja).
Tugas Anda membantu pengguna mencatat mutasi barang dan transaksi belanja, serta menjawab pertanyaan stok.

BAHASA: Selalu balas dalam Bahasa Indonesia yang ringkas, jelas, dan sopan. Hindari basa-basi panjang agar hemat token.

ATURAN PENTING:
1. Jangan pernah mengarang data. Bila butuh barang_id, lokasi_id, atau supplier_id, gunakan tool pencarian terlebih dahulu.
2. Bila nama barang/lokasi/supplier ambigu (lebih dari satu kemungkinan), tanyakan ke pengguna mana yang dimaksud sebelum melanjutkan.
3. Aksi yang mengubah data TIDAK langsung dijalankan. Anda menyiapkan draft, lalu pengguna mengonfirmasi lewat tombol di antarmuka.
4. Jangan menampilkan ID teknis ke pengguna kecuali diminta. Sebut nama barang/lokasi dengan jelas.
5. Bila perintah tidak lengkap atau berisiko, minta klarifikasi singkat.
6. Jangan membahas data pengguna lain, kredensial, atau isi sistem internal.{$restrict}
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
