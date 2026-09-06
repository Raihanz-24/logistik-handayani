<?php

namespace App\Filament\Pages;

use App\Models\FotoBarangEdit;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FotoBarangEditService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

class FotoBarangEditor extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Catatan';

    protected static ?string $navigationLabel = 'Editor Foto Maps';

    protected static ?string $title = 'Editor Foto Maps';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.foto-barang-editor';

    public ?int $selectedSessionId = null;

    public ?int $selectedPhotoId = null;

    public string $historyDate = '';

    public string $editDate = '';

    public string $editTime = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->historyDate = now('Asia/Jakarta')->toDateString();
    }

    public function selectSession(int $sessionId): void
    {
        $session = $this->visibleSessionsQuery()->findOrFail($sessionId);
        $this->selectedSessionId = (int) $session->getKey();
        $this->selectedPhotoId = null;
        $this->editDate = '';
        $this->editTime = '';
        $this->resetPage('editorOriginalsPage');
        $this->resetPage('editorResultsPage');
    }

    public function closeSession(): void
    {
        $this->selectedSessionId = null;
        $this->selectedPhotoId = null;
        $this->editDate = '';
        $this->editTime = '';
    }

    public function selectPhoto(int $photoId): void
    {
        $photo = $this->visiblePhotosQuery()
            ->where('foto_barang_items.id', $photoId)
            ->firstOrFail();
        $capturedAt = $photo->diambil_at?->setTimezone('Asia/Jakarta') ?? now('Asia/Jakarta');

        $this->selectedPhotoId = (int) $photo->getKey();
        $this->editDate = $capturedAt->format('Y-m-d');
        $this->editTime = $capturedAt->format('H:i');
        $this->resetValidation();
    }

    public function createEditedPhoto(FotoBarangEditService $editService): void
    {
        $data = $this->validate([
            'selectedPhotoId' => ['required', 'integer'],
            'editDate' => ['required', 'date_format:Y-m-d'],
            'editTime' => ['required', 'date_format:H:i'],
        ], [
            'selectedPhotoId.required' => 'Pilih foto terlebih dahulu.',
            'editDate.required' => 'Tanggal baru wajib diisi.',
            'editTime.required' => 'Jam baru wajib diisi.',
        ]);

        try {
            $revisedAt = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i',
                $data['editDate'].' '.$data['editTime'],
                'Asia/Jakarta',
            );

            if (! $revisedAt || $revisedAt->isAfter(now('Asia/Jakarta'))) {
                Notification::make()
                    ->title('Tanggal atau jam tidak valid')
                    ->body('Waktu hasil edit tidak boleh melewati waktu sekarang.')
                    ->warning()
                    ->send();

                return;
            }

            $photo = $this->visiblePhotosQuery()
                ->where('foto_barang_items.id', (int) $data['selectedPhotoId'])
                ->firstOrFail();
            $edit = $editService->create($photo, $revisedAt, auth()->user());

            app(AuditLogger::class)->activity(
                'foto_barang_edit_create',
                "Membuat hasil edit waktu foto nomor {$photo->urutan}",
                auth()->user(),
                [
                    'session_id' => $photo->foto_barang_session_id,
                    'photo_id' => $photo->getKey(),
                    'edit_id' => $edit->getKey(),
                    'waktu_asli' => $photo->diambil_at?->toIso8601String(),
                    'waktu_baru' => $revisedAt->toIso8601String(),
                ],
            );

            $this->resetPage('editorResultsPage');

            Notification::make()
                ->title('Hasil edit berhasil dibuat')
                ->body('Foto asli tetap tersimpan dan tidak mengalami perubahan.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Hasil edit gagal dibuat')
                ->body('Foto asli tetap aman. Silakan coba kembali atau periksa log aplikasi jika masalah berlanjut.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /** @return array{copied: bool, duplicate?: bool, message: string} */
    public function copyEditedPhoto(
        FotoBarangEditService $editService,
        int $editId,
        int $destinationSessionId,
    ): array {
        $this->skipRender();

        try {
            $edit = $this->visibleEditsQuery()->findOrFail($editId);
            $destination = $this->visibleSessionsQuery()->findOrFail($destinationSessionId);
            $result = $editService->copyToSession($edit, $destination);
            $item = $result['item'];
            $duplicate = (bool) $result['duplicate'];

            if (! $duplicate) {
                app(AuditLogger::class)->activity(
                    'foto_barang_edit_copy',
                    "Menyalin hasil edit foto ke folder: {$destination->judul}",
                    auth()->user(),
                    [
                        'edit_id' => $edit->getKey(),
                        'source_photo_id' => $edit->foto_barang_item_id,
                        'destination_session_id' => $destination->getKey(),
                        'destination_photo_id' => $item->getKey(),
                        'sequence' => $item->urutan,
                    ],
                );
            }

            $message = $duplicate
                ? 'Foto ini sebelumnya sudah ada di folder tujuan.'
                : "Foto berhasil disalin sebagai foto nomor {$item->urutan}.";

            $notification = Notification::make()
                ->title($duplicate ? 'Foto sudah tersedia' : 'Foto berhasil disalin')
                ->body($destination->judul.' · '.$message);

            if ($duplicate) {
                $notification->info();
            } else {
                $notification->success();
            }

            $notification->send();

            return [
                'copied' => true,
                'duplicate' => $duplicate,
                'message' => $message,
            ];
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Foto gagal disalin')
                ->body('Foto asli dan hasil edit tetap aman. Silakan coba kembali.')
                ->danger()
                ->send();

            return [
                'copied' => false,
                'message' => 'Foto gagal disalin. Periksa koneksi lalu coba kembali.',
            ];
        }
    }

    /** @return array{copied: bool, copied_count?: int, duplicate_count?: int, message: string} */
    public function copyEditedPhotos(
        FotoBarangEditService $editService,
        array $editIds,
        int $destinationSessionId,
    ): array {
        $this->skipRender();

        try {
            $normalizedIds = collect($editIds)
                ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($normalizedIds->isEmpty()) {
                throw new RuntimeException('Pilih minimal satu foto hasil edit.');
            }

            if ($normalizedIds->count() > 100) {
                throw new RuntimeException('Maksimal 100 foto dalam satu proses penyalinan.');
            }

            $edits = $this->visibleEditsQuery()
                ->whereKey($normalizedIds->all())
                ->get()
                ->keyBy(fn (FotoBarangEdit $edit): int => (int) $edit->getKey());

            if ($edits->count() !== $normalizedIds->count()) {
                throw new RuntimeException('Salah satu hasil edit tidak tersedia atau tidak dapat diakses.');
            }

            $orderedEdits = $normalizedIds->map(
                fn (int $id): FotoBarangEdit => $edits->get($id),
            );
            $destination = $this->visibleSessionsQuery()->findOrFail($destinationSessionId);
            $result = $editService->copyManyToSession($orderedEdits, $destination);
            $copiedCount = (int) $result['copied_count'];
            $duplicateCount = (int) $result['duplicate_count'];

            if ($copiedCount > 0) {
                app(AuditLogger::class)->activity(
                    'foto_barang_edit_bulk_copy',
                    "Menyalin {$copiedCount} hasil edit foto ke folder: {$destination->judul}",
                    auth()->user(),
                    [
                        'edit_ids' => $normalizedIds->all(),
                        'destination_session_id' => $destination->getKey(),
                        'destination_photo_ids' => collect($result['items'])->pluck('id')->all(),
                        'copied_count' => $copiedCount,
                        'duplicate_count' => $duplicateCount,
                    ],
                );
            }

            $message = "{$copiedCount} foto berhasil disalin";

            if ($duplicateCount > 0) {
                $message .= " · {$duplicateCount} foto sebelumnya sudah tersedia";
            }

            $notification = Notification::make()
                ->title($copiedCount > 0 ? 'Foto terpilih berhasil disalin' : 'Semua foto sudah tersedia')
                ->body($destination->judul.' · '.$message.'.');

            if ($copiedCount > 0) {
                $notification->success();
            } else {
                $notification->info();
            }

            $notification->send();

            return [
                'copied' => true,
                'copied_count' => $copiedCount,
                'duplicate_count' => $duplicateCount,
                'message' => $message,
            ];
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Foto terpilih gagal disalin')
                ->body('Tidak ada file asli atau hasil edit yang diubah. Silakan coba kembali.')
                ->danger()
                ->send();

            return [
                'copied' => false,
                'message' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Foto gagal disalin. Periksa koneksi lalu coba kembali.',
            ];
        }
    }

    public function updatedHistoryDate(): void
    {
        $this->resetPage('editorSessionsPage');
    }

    public function showTodaySessions(): void
    {
        $this->historyDate = now('Asia/Jakarta')->toDateString();
        $this->resetPage('editorSessionsPage');
    }

    public function showAllSessions(): void
    {
        $this->historyDate = '';
        $this->resetPage('editorSessionsPage');
    }

    /** @return LengthAwarePaginator<FotoBarangSession> */
    public function sessions(): LengthAwarePaginator
    {
        $query = $this->visibleSessionsQuery()
            ->whereHas('items', fn (Builder $query): Builder => $query
                ->where('processing_status', FotoBarangItem::PROCESSING_COMPLETED))
            ->withCount([
                'items as completed_items_count' => fn (Builder $query): Builder => $query
                    ->where('processing_status', FotoBarangItem::PROCESSING_COMPLETED),
            ]);

        if ($this->historyDate !== '') {
            $query->whereDate('dimulai_at', $this->historyDate);
        }

        return $query
            ->latest('dimulai_at')
            ->paginate(8, ['*'], 'editorSessionsPage');
    }

    public function selectedSession(): ?FotoBarangSession
    {
        if ($this->selectedSessionId === null) {
            return null;
        }

        return $this->visibleSessionsQuery()->find($this->selectedSessionId);
    }

    /** @return LengthAwarePaginator<FotoBarangItem> */
    public function originalPhotos(): LengthAwarePaginator
    {
        return $this->visiblePhotosQuery()
            ->latest('diambil_at')
            ->paginate(12, ['foto_barang_items.*'], 'editorOriginalsPage');
    }

    /** @return LengthAwarePaginator<FotoBarangEdit> */
    public function editedPhotos(): LengthAwarePaginator
    {
        return $this->visibleEditsQuery()
            ->with(['photo:id,foto_barang_session_id,urutan'])
            ->latest()
            ->paginate(12, ['*'], 'editorResultsPage');
    }

    /** @return Collection<int, FotoBarangSession> */
    public function copyDestinationSessions(): Collection
    {
        return $this->visibleSessionsQuery()
            ->withCount('items')
            ->latest('dimulai_at')
            ->limit(100)
            ->get();
    }

    public function selectedPhoto(): ?FotoBarangItem
    {
        if ($this->selectedPhotoId === null) {
            return null;
        }

        return $this->visiblePhotosQuery()
            ->where('foto_barang_items.id', $this->selectedPhotoId)
            ->first();
    }

    private function visibleSessionsQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangSession::query()->visibleTo($user);
    }

    private function visiblePhotosQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangItem::query()
            ->where('foto_barang_session_id', $this->selectedSessionId ?? 0)
            ->where('processing_status', FotoBarangItem::PROCESSING_COMPLETED)
            ->whereHas('session', fn (Builder $query): Builder => $query
                ->visibleTo($user))
            ->with('session');
    }

    private function visibleEditsQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangEdit::query()
            ->whereHas('photo', fn (Builder $query): Builder => $query
                ->where('foto_barang_session_id', $this->selectedSessionId ?? 0)
                ->whereHas('session', fn (Builder $sessionQuery): Builder => $sessionQuery
                    ->visibleTo($user)));
    }
}
