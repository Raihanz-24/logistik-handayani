<?php

namespace App\Services\Ai\Local;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Tools\CariBarangTool;
use App\Services\Ai\Tools\CatatTransaksiBelanjaTool;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * "Kecerdasan lokal" — jalur cepat DETERMINISTIK (tanpa memanggil API model).
 *
 * Menangani perintah belanja yang JELAS & terstruktur:
 * resolusi supplier + barang deterministik, lalu menyiapkan draft.
 * Bila ragu sedikit pun (supplier tak dikenali, barang ambigu, format aneh),
 * kembalikan null supaya diteruskan ke model API. Dengan begitu:
 *   - hemat token  (perintah simpel tak perlu panggil model),
 *   - lebih akurat  (pemetaan nama->barang deterministik, tanpa "mengarang").
 */
class LocalIntentService
{
    public function __construct(
        private readonly BelanjaPerintahParser $parser,
        private readonly CariBarangTool $cariBarang,
        private readonly CatatTransaksiBelanjaTool $catatBelanja,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ai.local_intelligence.enabled', true);
    }

    /**
     * Coba tangani pesan secara lokal. Mengembalikan pesan balasan bila berhasil,
     * atau null bila harus diteruskan ke model API.
     */
    public function tryHandle(User $user, AiConversation $conversation, string $userMessage): ?AiMessage
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->handleBelanja($user, $conversation, $userMessage);
        } catch (Throwable) {
            // Kegagalan jalur lokal TIDAK boleh menggagalkan percakapan.
            return null;
        }
    }

    private function handleBelanja(User $user, AiConversation $conversation, string $userMessage): ?AiMessage
    {
        // Butuh izin menyiapkan draft belanja; tanpa itu, serahkan ke model.
        if (! Gate::forUser($user)->allows('update_kalkulator_belanja')
            || ! Gate::forUser($user)->allows('view_any_barang')) {
            return null;
        }

        $suppliers = Supplier::query()->aktif()
            ->get(['id', 'nama_supplier'])
            ->map(fn (Supplier $s): array => ['id' => (int) $s->getKey(), 'nama' => (string) $s->nama_supplier])
            ->all();

        if ($suppliers === []) {
            return null;
        }

        $parsed = $this->parser->parse($userMessage, $suppliers);

        if ($parsed === null) {
            return null;
        }

        $supplierId = (int) $parsed['supplier_id'];
        $items = [];
        $jumlahDikenali = 0;

        foreach ($parsed['items'] as $line) {
            $hasil = $this->cariBarang->execute([
                'kata_kunci' => $line['nama'],
                'supplier_id' => $supplierId,
                'limit' => 5,
            ], $user, $conversation);

            $pilihan = $hasil['pilihan_terbaik'] ?? null;

            // Barang ambigu / tak ditemukan -> jangan menebak; serahkan ke model.
            if (! is_array($pilihan) || empty($pilihan['barang_id'])) {
                return null;
            }

            $items[] = [
                'barang_id' => (int) $pilihan['barang_id'],
                'jumlah' => $line['jumlah'],
                'harga_satuan' => $line['harga_satuan'],
                'satuan' => $line['satuan'],
            ];

            $jumlahDikenali++;
        }

        if ($items === [] || $jumlahDikenali !== count($parsed['items'])) {
            return null;
        }

        // Barang ganda akan ditolak tool; serahkan ke model agar digabung rapi.
        $ids = array_column($items, 'barang_id');

        if (count($ids) !== count(array_unique($ids))) {
            return null;
        }

        $result = $this->catatBelanja->execute([
            'supplier_id' => $supplierId,
            'items' => $items,
        ], $user, $conversation);

        if (! isset($result['pending_action_id'])) {
            return null;
        }

        $balasan = $this->composeReply($supplierId, $result, $items);

        return $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $balasan,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $items
     */
    private function composeReply(int $supplierId, array $result, array $items): string
    {
        $supplier = (string) Supplier::query()->whereKey($supplierId)->value('nama_supplier');
        $names = Barang::query()
            ->whereKey(array_column($items, 'barang_id'))
            ->pluck('nama_barang', 'id');

        $total = 0;
        $lines = [];

        foreach ($items as $index => $item) {
            $nama = $names[(int) $item['barang_id']] ?? ('barang #'.$item['barang_id']);
            $satuan = filled($item['satuan'] ?? null) ? (string) $item['satuan'] : '-';
            $jumlah = rtrim(rtrim(number_format((float) $item['jumlah'], 3, ',', '.'), '0'), ',');
            $harga = (int) $item['harga_satuan'];
            $total += (int) round($harga * (float) $item['jumlah']);

            $lines[] = ($index + 1).'. '.$nama.', '.$jumlah.' '.$satuan.', '.number_format($harga, 0, ',', '.').'/';
        }

        $head = 'Siap, saya susun draft belanja ke '.$supplier.':';
        $foot = 'Total: Rp'.number_format($total, 0, ',', '.')
            ."\nTekan \"Catat Transaksi Belanja\" pada kartu untuk menyimpan.";

        if (! empty($result['harga_otomatis_dari_histori'])) {
            $foot = "Catatan: sebagian harga diisi dari harga terakhir.\n".$foot;
        }

        return $head."\n".implode("\n", $lines)."\n".$foot;
    }
}
