<?php

namespace App\Filament\Pages;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\AiActionService;
use App\Services\Ai\AiChatService;
use App\Services\Ai\AiClientException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Halaman Asisten Logistik (chat). Tahap 3: teks saja dengan tool read-only.
 *
 * - Hanya muncul/aktif bila AI_ENABLED=true.
 * - Diakses bila user punya salah satu permission terkait (super_admin selalu).
 * - Tidak pernah commit mutasi/belanja (itu tahap berikutnya via konfirmasi).
 */
class AsistenAi extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Bantuan';

    protected static ?string $navigationLabel = 'Asisten AI';

    protected static ?string $title = 'Asisten AI';

    protected static ?int $navigationSort = 998;

    protected static ?string $slug = 'asisten-ai';

    protected static string $view = 'filament.pages.asisten-ai';

    // Halaman fokus penuh ke chat; judul besar Filament dimatikan agar area
    // percakapan maksimal. Identitas asisten ditampilkan di bilah dalam chat.
    protected ?string $heading = '';

    protected ?string $subheading = null;

    protected ?string $maxContentWidth = 'full';

    /** ID percakapan aktif (null = percakapan baru). */
    public ?int $conversationId = null;

    /** Isi kotak pesan. */
    public string $message = '';

    /** Status drawer riwayat (dikelola Livewire agar tidak bertabrakan dengan morph). */
    public bool $historyOpen = false;

    /**
     * Konfirmasi hapus riwayat dengan modal internal (bukan pop-up browser).
     * Bernilai 'all' untuk hapus semua, atau sebuah ID percakapan untuk hapus satu.
     */
    public ?string $deleteConfirmScope = null;

    /** ID percakapan yang akan dihapus saat mode 'single'. */
    public ?int $deleteConfirmConversationId = null;

    public static function canAccess(): bool
    {
        if (! config('ai.enabled')) {
            return false;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->can('view_any_barang')
            || $user->can('view_any_barang::lokasi')
            || $user->can('view_any_mutasi');
    }

    /**
     * Saat halaman dibuka kembali (termasuk setelah berpindah halaman lewat SPA),
     * lanjutkan percakapan terakhir bila masih ada. Ini mencegah konteks dan kartu
     * konfirmasi "hilang" tiap kali user berpindah menu.
     */
    public function mount(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $id = session('ai_active_conversation_id');

        if (! $id) {
            return;
        }

        $conversation = AiConversation::query()->visibleTo($user)->find($id);

        if ($conversation) {
            $this->conversationId = (int) $conversation->getKey();
        } else {
            session()->forget('ai_active_conversation_id');
        }
    }

    public function sendMessage(): void
    {
        $text = trim($this->message);

        if ($text === '') {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if (! config('ai.enabled')) {
            Notification::make()->title('Asisten AI sedang nonaktif.')->danger()->send();

            return;
        }

        $conversation = $this->ensureConversation($user);

        if (blank($conversation->title)) {
            $conversation->update(['title' => Str::limit($text, 60, '')]);
        }

        session(['ai_active_conversation_id' => $conversation->getKey()]);

        $this->message = '';

        try {
            $reply = app(AiChatService::class)->reply($user, $conversation, $text);

            if ($reply->content !== '') {
                $this->dispatch('ai-reply', text: $reply->content);
            }
        } catch (AiClientException $exception) {
            $this->message = $text;
            Notification::make()->title($exception->getMessage())->danger()->send();
        } catch (Throwable $exception) {
            $this->message = $text;
            report($exception);
            Notification::make()
                ->title('Terjadi kesalahan saat menghubungi asisten. Coba lagi.')
                ->danger()
                ->send();
        }
    }

    public function startNewChat(): void
    {
        $this->conversationId = null;
        $this->message = '';
        $this->historyOpen = false;
        $this->closeDeleteConfirm();
        session()->forget('ai_active_conversation_id');
    }

    /** Isi kotak pesan dari kartu saran di layar kosong. */
    public function useSuggestion(int $index): void
    {
        $this->message = self::SUGGESTIONS[$index] ?? '';
    }

    /** Saran cepat yang ditampilkan pada layar kosong. */
    public const SUGGESTIONS = [
        'Berapa stok air galon di gudang utama?',
        'Buatkan draft mutasi masuk 20 air galon ke gudang utama',
        'Catat belanja 3 sabun cuci piring dari toko bu ciko',
    ];

    public function confirmAction(int $actionId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $action = AiPendingAction::query()->findOrFail($actionId);
            $result = app(AiActionService::class)->confirm($action, $user);

            Notification::make()
                ->title($result['label'])
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->title('Gagal menjalankan aksi')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function cancelAction(int $actionId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $action = AiPendingAction::query()->findOrFail($actionId);
            app(AiActionService::class)->cancel($action, $user);

            Notification::make()->title('Aksi dibatalkan')->success()->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->title('Gagal membatalkan aksi')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function openConversation(int $id): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $conversation = AiConversation::query()->visibleTo($user)->find($id);

        if (! $conversation) {
            Notification::make()->title('Percakapan tidak ditemukan.')->danger()->send();

            return;
        }

        $this->conversationId = (int) $conversation->getKey();
        $this->message = '';
        $this->historyOpen = false;
        $this->closeDeleteConfirm();
        session(['ai_active_conversation_id' => $conversation->getKey()]);
    }

    /** Buka/tutup drawer riwayat percakapan. */
    public function toggleHistory(): void
    {
        $this->historyOpen = ! $this->historyOpen;

        if (! $this->historyOpen) {
            $this->closeDeleteConfirm();
        }
    }

    public function closeHistory(): void
    {
        $this->historyOpen = false;
        $this->closeDeleteConfirm();
    }

    /** Tampilkan modal konfirmasi (internal) untuk hapus satu percakapan. */
    public function confirmDeleteConversation(int $id): void
    {
        $this->deleteConfirmScope = 'single';
        $this->deleteConfirmConversationId = $id;
    }

    /** Tampilkan modal konfirmasi (internal) untuk hapus semua percakapan. */
    public function confirmClearConversations(): void
    {
        $this->deleteConfirmScope = 'all';
        $this->deleteConfirmConversationId = null;
    }

    /** Tutup modal konfirmasi tanpa menghapus. */
    public function closeDeleteConfirm(): void
    {
        $this->deleteConfirmScope = null;
        $this->deleteConfirmConversationId = null;
    }

    /** Jalankan penghapusan sesuai mode modal konfirmasi (dipanggil tombol modal). */
    public function confirmDeleteConfirmed(): void
    {
        if ($this->deleteConfirmScope === 'all') {
            $this->closeDeleteConfirm();
            $this->clearConversations();

            return;
        }

        if ($this->deleteConfirmScope === 'single' && $this->deleteConfirmConversationId) {
            $id = (int) $this->deleteConfirmConversationId;
            $this->closeDeleteConfirm();
            $this->deleteConversation($id);

            return;
        }

        $this->closeDeleteConfirm();
    }

    /** Hapus satu percakapan beserta seluruh pesan & aksi pending-nya. */
    public function deleteConversation(int $id): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $conversation = AiConversation::query()->visibleTo($user)->find($id);

        if (! $conversation) {
            Notification::make()->title('Percakapan tidak ditemukan.')->danger()->send();

            return;
        }

        // Jangan hapus bila masih ada aksi menunggu konfirmasi, agar draft tidak
        // "hilang" tanpa jejak.
        $hasPending = $conversation->pendingActions()
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            Notification::make()
                ->title('Konfirmasi atau batalkan aksi yang masih menunggu dulu.')
                ->warning()
                ->send();

            return;
        }

        $isActive = (int) $this->conversationId === (int) $conversation->getKey();

        // pesan & lampiran akan ikut terhapus via foreign key cascade.
        $conversation->delete();

        if ($isActive) {
            $this->conversationId = null;
            $this->message = '';
            session()->forget('ai_active_conversation_id');
        }

        Notification::make()->title('Riwayat percakapan dihapus.')->success()->send();
    }

    /** Hapus seluruh percakapan milik user (kecuali yang masih punya aksi pending). */
    public function clearConversations(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $query = AiConversation::query()->visibleTo($user);

        $query->get()->each(function (AiConversation $conversation): void {
            $hasPending = $conversation->pendingActions()
                ->where('status', AiPendingAction::STATUS_PENDING)
                ->exists();

            if (! $hasPending) {
                $conversation->delete();
            }
        });

        $this->conversationId = null;
        $this->message = '';
        session()->forget('ai_active_conversation_id');

        Notification::make()->title('Semua riwayat percakapan dihapus.')->success()->send();
    }

    /** @return Collection<int, AiMessage> */
    public function getMessagesProperty(): Collection
    {
        $conversation = $this->conversation();

        if (! $conversation) {
            return collect();
        }

        // Relasi messages() sudah terurut id ASC. Ambil N terakhir dengan urutan
        // tetap (terlama → terbaru) agar bubble tampil menaik ke bawah.
        return $conversation->messages()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->get()
            ->filter(fn (AiMessage $message): bool => $message->role === AiMessage::ROLE_USER
                || filled($message->content))
            ->take(-100)
            ->values();
    }

    /** @return Collection<int, AiConversation> */
    public function getRecentConversationsProperty(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return AiConversation::query()
            ->visibleTo($user)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /** @return Collection<int, AiPendingAction> */
    public function getPendingActionsProperty(): Collection
    {
        if (! $this->conversationId) {
            return collect();
        }

        return AiPendingAction::query()
            ->where('ai_conversation_id', $this->conversationId)
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->orderBy('id')
            ->get();
    }

    public function getIsConfiguredProperty(): bool
    {
        return app(\App\Services\Ai\DeepSeekClient::class)->isConfigured();
    }

    private function conversation(): ?AiConversation
    {
        if (! $this->conversationId) {
            return null;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return AiConversation::query()->visibleTo($user)->find($this->conversationId);
    }

    private function ensureConversation(User $user): AiConversation
    {
        $existing = $this->conversation();

        if ($existing) {
            return $existing;
        }

        $conversation = app(AiChatService::class)->startConversation($user);
        $this->conversationId = (int) $conversation->getKey();

        return $conversation;
    }
}
