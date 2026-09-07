<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessFotoBarangImage;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FotoBarangDeletionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

class FotoBarangFolder extends Page
{
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'foto-barang-maps/folder/{session}';

    protected static ?string $title = 'Folder Foto Maps';

    protected static string $view = 'filament.pages.foto-barang-folder';

    public string $sessionUuid = '';

    private ?FotoBarangSession $resolvedFolder = null;

    public function mount(string $session): void
    {
        $folder = $this->visibleSessionsQuery()
            ->where('uuid', $session)
            ->firstOrFail();

        $this->sessionUuid = (string) $folder->uuid;
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return $this->folder()->judul;
    }

    public function folder(): FotoBarangSession
    {
        return $this->resolvedFolder ??= $this->visibleSessionsQuery()
            ->with([
                'pengeluaranBelanjas.supplier',
                'pengeluaranBelanjas.kalkulatorBelanja',
            ])
            ->withCount('items')
            ->where('uuid', $this->sessionUuid)
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<FotoBarangItem> */
    public function photos(): LengthAwarePaginator
    {
        return $this->folder()
            ->items()
            ->reorder()
            ->latest('urutan')
            ->paginate(12, ['*'], 'photosPage');
    }

    /** @return array{deleted: bool, photo_ids: array<int, int>, message?: string} */
    public function deleteSelectedPhotos(
        FotoBarangDeletionService $deletionService,
        array $photoIds,
        string $confirmation,
    ): array {
        $this->skipRender();

        if (strtolower(trim($confirmation)) !== 'hapus') {
            return [
                'deleted' => false,
                'photo_ids' => [],
                'message' => 'Ketik hapus untuk mengonfirmasi penghapusan foto.',
            ];
        }

        try {
            $normalizedIds = collect($photoIds)
                ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($normalizedIds->isEmpty() || $normalizedIds->count() > 100) {
                throw new RuntimeException('Pilih antara 1 sampai 100 foto.');
            }

            $folder = $this->folder();
            $deletedIds = $deletionService->deleteMany($folder, $normalizedIds->all());

            app(AuditLogger::class)->activity(
                count($deletedIds) === 1 ? 'foto_barang_delete' : 'foto_barang_bulk_delete',
                'Menghapus '.count($deletedIds)." foto dari sesi: {$folder->judul}",
                auth()->user(),
                [
                    'session_id' => $folder->getKey(),
                    'photo_ids' => $deletedIds,
                    'photo_count' => count($deletedIds),
                ],
            );

            Notification::make()
                ->title(count($deletedIds).' foto berhasil dihapus')
                ->success()
                ->send();

            $this->resetPage('photosPage');

            return ['deleted' => true, 'photo_ids' => $deletedIds];
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Foto gagal dihapus')
                ->body('File sumber tetap dipulihkan bila transaksi gagal.')
                ->danger()
                ->send();

            return [
                'deleted' => false,
                'photo_ids' => [],
                'message' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Penghapusan foto gagal. Silakan coba kembali.',
            ];
        }
    }

    public function retryPhotoProcessing(int $photoId): void
    {
        $folder = $this->folder();
        $photo = $folder->items()->whereKey($photoId)->firstOrFail();

        if ($photo->processingCompleted()) {
            return;
        }

        $photo->update([
            'processing_status' => FotoBarangItem::PROCESSING_PENDING,
            'processing_error' => null,
        ]);

        $queue = (string) config('foto_barang.processing_queue', 'default');

        if (config('foto_barang.processing_mode') === 'queue') {
            ProcessFotoBarangImage::dispatch((int) $photo->getKey())->onQueue($queue);
        } else {
            ProcessFotoBarangImage::dispatchAfterResponse((int) $photo->getKey())->onQueue($queue);
        }

        Notification::make()
            ->title('Foto dijadwalkan ulang')
            ->body('File sumber tetap aman selama proses berjalan.')
            ->success()
            ->send();
    }

    /** @return array{mode: string, total: int, photos: array<int, array<string, int|string>>} */
    public function shareManifest(): array
    {
        $folder = $this->folder();
        $total = (int) $folder->items_count;
        $totalBytes = (int) $folder->items()->sum('ukuran_hasil');

        if ($total > 30 || $totalBytes > 75 * 1024 * 1024) {
            return ['mode' => 'archive', 'total' => $total, 'photos' => []];
        }

        $photos = $folder->items()
            ->reorder()
            ->orderBy('urutan')
            ->get()
            ->map(fn (FotoBarangItem $photo): array => [
                'preview' => route('foto-barang.preview', [$folder, $photo]).'?v='.$photo->updated_at->getTimestamp(),
                'fileName' => $photo->fileName(),
                'fileSize' => (int) $photo->ukuran_hasil,
            ])
            ->all();

        return ['mode' => 'direct', 'total' => $total, 'photos' => $photos];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format(max(1, $bytes / 1024), 0, ',', '.').' KB';
    }

    private function visibleSessionsQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangSession::query()->visibleTo($user);
    }
}
