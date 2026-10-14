<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Base class untuk semua tool Asisten AI.
 *
 * Kontrak keamanan:
 * - Tool HANYA boleh mengakses data lewat permission user yang dikonfirmasi.
 * - Tool read-only tidak boleh mengubah data.
 * - Tool tulis hanya boleh MENYIAPKAN (draft) dan tidak commit sendiri.
 */
abstract class AiTool
{
    /** Nama tool yang dikirim ke model (snake_case). */
    abstract public function name(): string;

    /** Deskripsi untuk model: kapan & mengapa tool ini dipakai. */
    abstract public function description(): string;

    /**
     * JSON schema parameter (format OpenAI "parameters").
     *
     * @return array<string, mixed>
     */
    abstract public function parameters(): array;

    /**
     * Jalankan tool.
     *
     * @param  array<string, mixed>  $arguments
     * @param  AiConversation|null  $conversation  Konteks percakapan (untuk tool tulis).
     * @return array<string, mixed>
     *
     * @throws ToolException bila argumen tidak valid atau ditolak.
     */
    abstract public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array;

    /** Nama permission Shield yang dibutuhkan, atau null bila tidak perlu. */
    public function permission(): ?string
    {
        $configured = config('ai.permissions.'.$this->name());

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /** Apakah tool ini hanya membaca data (tidak mengubah apa pun)? */
    public function isReadOnly(): bool
    {
        return true;
    }

    /** Apakah tool ini membuat aksi yang harus dikonfirmasi user? */
    public function requiresConfirmation(): bool
    {
        return false;
    }

    /**
     * Batas ukuran output JSON (karakter) agar prompt tidak membengkak.
     */
    protected function limitPayload(array $payload, int $maxChars = 6000): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || mb_strlen($encoded) <= $maxChars) {
            return $payload;
        }

        return [
            'truncated' => true,
            'note' => 'Hasil dipotong karena terlalu panjang. Perjelas/kerucutkan pencarian.',
            'preview' => mb_substr($encoded, 0, $maxChars),
        ];
    }

    /**
     * Tandai aksi pending lama (tipe sama, percakapan sama) sebagai "superseded".
     *
     * Dipanggil tool tulis SEBELUM membuat draft baru, agar kartu konfirmasi
     * tidak menumpuk: hanya draft terbaru yang tampil & bisa dikonfirmasi.
     * Draft lama tidak dihapus (jejak audit tetap ada) hanya digantikan.
     */
    protected function supersedePendingActions(?AiConversation $conversation, string $type): void
    {
        if (! $conversation) {
            return;
        }

        AiPendingAction::query()
            ->where('ai_conversation_id', $conversation->getKey())
            ->where('type', $type)
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->update(['status' => AiPendingAction::STATUS_SUPERSEDED]);
    }

    /**
     * Pastikan user memiliki izin memanggil tool ini.
     *
     * @throws ToolException
     */
    public function authorize(User $user): void
    {
        $permission = $this->permission();

        if ($permission === null) {
            return;
        }

        if (! Gate::forUser($user)->allows($permission)) {
            throw new ToolException(
                'Anda tidak memiliki izin untuk aksi ini ('.$permission.').'
            );
        }
    }
}
