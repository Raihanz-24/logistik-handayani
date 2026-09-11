<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessFotoBarangImage;
use App\Models\Barang;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\PengeluaranBelanja;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FotoBarangDeletionService;
use App\Services\FotoBarangImageService;
use App\Services\FotoBarangPurchaseItemLinkService;
use App\Services\ReverseGeocodingService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class FotoBarangMaps extends Page
{
    use WithFileUploads;
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Catatan';

    protected static ?string $navigationLabel = 'Foto Maps Barang';

    protected static ?string $title = 'Foto Maps Barang Datang';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.foto-barang-maps';

    public ?int $activeSessionId = null;

    public string $judul = '';

    public string $namaLokasi = '';

    public string $alamat = '';

    public mixed $photo = null;

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?int $accuracy = null;

    public ?string $capturedAt = null;

    public ?string $clientCaptureId = null;

    public int $uploadKey = 0;

    #[Url(as: 'tanggal')]
    public string $historyDate = '';

    public ?int $pendingPengeluaranId = null;

    /** Barang opsional untuk setiap foto yang diambil pada mode server. */
    public ?int $selectedBarangId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->hasRole('super_admin')
                || $user->can('view_foto_barang_maps')
                || $user->can('manage_foto_barang_maps'));
    }

    public function canManagePhotos(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->hasRole('super_admin') || $user->can('manage_foto_barang_maps'));
    }

    public function mount(): void
    {
        $this->resetSessionForm();
        $this->historyDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->historyDate) || $this->historyDate === 'all'
            ? $this->historyDate
            : now('Asia/Jakarta')->toDateString();

        $requestedExpenseId = (int) request()->query('pengeluaran', 0);
        $this->pendingPengeluaranId = $requestedExpenseId > 0 && $this->visibleExpensesQuery()
            ->whereKey($requestedExpenseId)
            ->exists()
                ? $requestedExpenseId
                : null;

        $requestedUuid = trim((string) request()->query('session', ''));

        if ($requestedUuid !== '') {
            $requested = $this->visibleSessionsQuery()
                ->where('uuid', $requestedUuid)
                ->first();

            if ($requested) {
                $this->activeSessionId = (int) $requested->getKey();

                return;
            }
        }

        $active = FotoBarangSession::query()
            ->where('user_id', auth()->id())
            ->where('status', FotoBarangSession::STATUS_AKTIF)
            ->latest('dimulai_at')
            ->first();

        $this->activeSessionId = $active?->getKey();
    }

    public function startSession(): void
    {
        $this->ensureCanManagePhotos();

        $data = $this->validate([
            'judul' => ['required', 'string', 'max:150'],
        ], [
            'judul.required' => 'Nama sesi wajib diisi.',
        ]);

        $existing = FotoBarangSession::query()
            ->where('user_id', auth()->id())
            ->where('status', FotoBarangSession::STATUS_AKTIF)
            ->latest('dimulai_at')
            ->first();

        if ($existing) {
            $this->activeSessionId = (int) $existing->getKey();

            Notification::make()
                ->title('Masih ada sesi aktif')
                ->body('Selesaikan sesi yang sedang berjalan sebelum membuat folder baru.')
                ->warning()
                ->send();

            return;
        }

        $session = FotoBarangSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => auth()->id(),
            'judul' => $data['judul'],
            'nama_lokasi' => 'Menunggu lokasi GPS',
            'alamat' => 'Alamat akan diambil otomatis saat kamera dibuka',
            'status' => FotoBarangSession::STATUS_AKTIF,
            'dimulai_at' => now('Asia/Jakarta'),
        ]);

        $this->activeSessionId = (int) $session->getKey();
        $this->selectedBarangId = null;

        if ($expense = $this->pendingExpense()) {
            $session->pengeluaranBelanjas()->syncWithoutDetaching([$expense->getKey()]);

            app(AuditLogger::class)->activity(
                'foto_session_belanja_link',
                'Menghubungkan sesi Foto Maps ke transaksi: '.$expense->namaSupplier(),
                auth()->user(),
                [
                    'session_id' => $session->getKey(),
                    'pengeluaran_belanja_id' => $expense->getKey(),
                ],
            );

            $this->pendingPengeluaranId = null;
        }

        app(AuditLogger::class)->activity(
            'foto_session_create',
            "Membuat sesi foto barang: {$session->judul}",
            auth()->user(),
            ['session_id' => $session->getKey(), 'code' => $session->code()],
        );

        Notification::make()
            ->title('Sesi foto dimulai')
            ->body('Foto berikutnya otomatis masuk ke folder '.$session->code().'.')
            ->success()
            ->send();
    }

    public function pendingExpense(): ?PengeluaranBelanja
    {
        if (! $this->pendingPengeluaranId) {
            return null;
        }

        return $this->visibleExpensesQuery()
            ->with(['supplier', 'kalkulatorBelanja'])
            ->find($this->pendingPengeluaranId);
    }

    public function updatedPhoto(): void
    {
        $this->ensureCanManagePhotos();

        $isLiveCapture = filled($this->capturedAt) || filled($this->clientCaptureId);

        if ($isLiveCapture) {
            $this->skipRender();
        }

        $this->validateOnly('photo', [
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('foto_barang.max_upload_kb', 10240),
            ],
        ], [
            'photo.image' => 'File yang dipilih harus berupa gambar.',
            'photo.mimes' => 'Gunakan foto JPG, PNG, atau WebP.',
            'photo.max' => 'Foto asli maksimal 10 MB.',
        ]);

        if ($this->latitude === null || $this->longitude === null) {
            Notification::make()
                ->title('Lokasi belum tersedia')
                ->body('Foto sudah dipilih. Aktifkan GPS, lalu tekan Proses Foto.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $this->savePhoto();
    }

    public function savePhoto(): void
    {
        $this->ensureCanManagePhotos();

        $isLiveCapture = filled($this->capturedAt) || filled($this->clientCaptureId);

        if ($isLiveCapture) {
            // Respons upload kamera tidak boleh me-render ulang DOM karena akan memutus MediaStream.
            $this->skipRender();
        }

        $this->validate([
            'activeSessionId' => ['required', 'integer'],
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('foto_barang.max_upload_kb', 10240),
            ],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'clientCaptureId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'selectedBarangId' => ['nullable', 'integer', 'exists:barangs,id'],
        ], [
            'latitude.required' => 'Koordinat GPS belum tersedia.',
            'longitude.required' => 'Koordinat GPS belum tersedia.',
            'photo.max' => 'Foto asli maksimal 10 MB.',
        ]);

        if (! $this->photo instanceof TemporaryUploadedFile) {
            return;
        }

        $session = $this->findVisibleSession((int) $this->activeSessionId);

        if (! $session->isActive()) {
            Notification::make()->title('Sesi sudah selesai')->danger()->send();

            return;
        }

        try {
            $item = app(FotoBarangImageService::class)->stage(
                $session,
                $this->photo,
                (float) $this->latitude,
                (float) $this->longitude,
                $this->accuracy,
                $this->captureTime(),
                $this->clientCaptureId,
            );

            if ($item->wasRecentlyCreated) {
                app(AuditLogger::class)->activity(
                    'foto_barang_create',
                    "Menambahkan foto ke sesi: {$session->judul}",
                    auth()->user(),
                    [
                        'session_id' => $session->getKey(),
                        'photo_id' => $item->getKey(),
                        'sequence' => $item->urutan,
                    ],
                );

                $this->dispatchPhotoProcessing($item);

                $this->autoAssignCapturedPhoto($session, $item, $this->selectedBarangId);
            }

            $this->reset('photo', 'capturedAt', 'clientCaptureId');
            $this->uploadKey++;
            $this->dispatch('foto-barang-saved');

            Notification::make()
                ->title("Foto {$item->urutan} tersimpan")
                ->body('File sumber sudah aman. Watermark dan kompresi diproses di latar belakang.')
                ->success()
                ->send();

        } catch (Throwable $exception) {
            report($exception);

            $this->dispatch('foto-barang-failed');

            Notification::make()
                ->title('Foto gagal disimpan')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

        }
    }

    public function updateCoordinates(float $latitude, float $longitude, ?float $accuracy = null): void
    {
        $this->ensureCanManagePhotos();

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return;
        }

        $this->latitude = round($latitude, 7);
        $this->longitude = round($longitude, 7);
        $this->accuracy = $accuracy === null ? null : max(0, (int) round($accuracy));
        $this->skipRender();
    }

    /** @return array{name: string, address: string, resolved: bool} */
    public function resolveSessionLocation(
        float $latitude,
        float $longitude,
        ?float $accuracy = null,
    ): array {
        $this->ensureCanManagePhotos();

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return [
                'name' => 'Lokasi GPS tidak valid',
                'address' => 'Koordinat lokasi tidak valid',
                'resolved' => false,
            ];
        }

        $this->latitude = round($latitude, 7);
        $this->longitude = round($longitude, 7);
        $this->accuracy = $accuracy === null ? null : max(0, (int) round($accuracy));
        $location = app(ReverseGeocodingService::class)->lookup($this->latitude, $this->longitude);
        $location['name'] = Str::limit(trim($location['name']), 255, '');
        $location['address'] = Str::limit(trim($location['address']), 1000, '');
        $session = $this->findVisibleSession((int) $this->activeSessionId);

        if ($session->isActive()) {
            $changed = $session->nama_lokasi !== $location['name'] || $session->alamat !== $location['address'];

            if ($changed) {
                $session->update([
                    'nama_lokasi' => $location['name'],
                    'alamat' => $location['address'],
                ]);

                app(AuditLogger::class)->activity(
                    'foto_session_location_update',
                    "Memperbarui lokasi otomatis sesi foto: {$session->judul}",
                    auth()->user(),
                    [
                        'session_id' => $session->getKey(),
                        'latitude' => $this->latitude,
                        'longitude' => $this->longitude,
                        'resolved' => $location['resolved'],
                    ],
                );
            }
        }

        $this->namaLokasi = $location['name'];
        $this->alamat = $location['address'];
        $this->skipRender();

        return $location;
    }

    /** @return array{name: string, address: string, latitude: float, longitude: float, resolved: bool, mode: string} */
    public function applyHandayaniTemplateLocation(): array
    {
        $this->ensureCanManagePhotos();

        $latitude = round((float) config('foto_barang.handayani_location.latitude', -7.717710), 7);
        $longitude = round((float) config('foto_barang.handayani_location.longitude', 113.537297), 7);
        $location = [
            'name' => Str::limit(trim((string) config(
                'foto_barang.handayani_location.name',
                'Kecamatan Paiton, Jawa Timur, Indonesia',
            )), 255, ''),
            'address' => Str::limit(trim((string) config(
                'foto_barang.handayani_location.address',
                'Jl. Raya Paiton No. KM 137, Dusun Matikan, Sumberejo, Kec. Paiton, Kabupaten Probolinggo, Jawa Timur 67291, Indonesia',
            )), 1000, ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'resolved' => true,
            'mode' => 'template_handayani',
        ];

        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->accuracy = null;
        $this->namaLokasi = $location['name'];
        $this->alamat = $location['address'];

        $session = $this->findVisibleSession((int) $this->activeSessionId);

        if ($session->isActive()) {
            $changed = $session->nama_lokasi !== $location['name'] || $session->alamat !== $location['address'];

            if ($changed) {
                $session->update([
                    'nama_lokasi' => $location['name'],
                    'alamat' => $location['address'],
                ]);

                app(AuditLogger::class)->activity(
                    'foto_session_location_template',
                    "Menggunakan lokasi template Handayani untuk sesi foto: {$session->judul}",
                    auth()->user(),
                    [
                        'session_id' => $session->getKey(),
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                );
            }
        }

        $this->skipRender();

        return $location;
    }

    public function updateCaptureMetadata(
        float $latitude,
        float $longitude,
        ?float $accuracy,
        string $capturedAt,
        ?string $clientCaptureId = null,
    ): void {
        $this->ensureCanManagePhotos();

        $this->updateCoordinates($latitude, $longitude, $accuracy);

        try {
            $captured = CarbonImmutable::parse($capturedAt)->setTimezone('Asia/Jakarta');
            $now = CarbonImmutable::now('Asia/Jakarta');

            if ($captured->diffInMinutes($now, true) > 10) {
                $captured = $now;
            }

            $this->capturedAt = $captured->toIso8601String();
        } catch (Throwable) {
            $this->capturedAt = CarbonImmutable::now('Asia/Jakarta')->toIso8601String();
        }

        $clientCaptureId = trim((string) $clientCaptureId);
        $this->clientCaptureId = preg_match('/^[A-Za-z0-9._:-]{10,100}$/', $clientCaptureId)
            ? $clientCaptureId
            : null;

        $this->skipRender();
    }

    public function finishSession(bool $allowEmptyLocal = false): void
    {
        $this->ensureCanManagePhotos();

        $session = $this->findVisibleSession((int) $this->activeSessionId);

        if (! $session->isActive()) {
            return;
        }

        if (! $allowEmptyLocal && ! $session->items()->exists()) {
            Notification::make()
                ->title('Sesi masih kosong')
                ->body('Ambil minimal satu foto sebelum menyelesaikan sesi.')
                ->warning()
                ->send();

            return;
        }

        $session->update([
            'status' => FotoBarangSession::STATUS_SELESAI,
            'selesai_at' => now('Asia/Jakarta'),
        ]);

        app(AuditLogger::class)->activity(
            'foto_session_complete',
            "Menyelesaikan sesi foto barang: {$session->judul}",
            auth()->user(),
            [
                'session_id' => $session->getKey(),
                'photo_count' => $session->items()->count(),
                'storage_mode' => $allowEmptyLocal ? 'local_device' : 'server',
            ],
        );

        Notification::make()
            ->title('Sesi selesai')
            ->body($allowEmptyLocal
                ? 'Sesi lokal selesai. Foto tetap tersedia hanya pada perangkat ini.'
                : 'Folder foto siap diunduh atau dibagikan sebagai laporan.')
            ->success()
            ->send();
    }

    /** @return array{deleted: bool, photo_id: int} */
    public function deletePhoto(int $photoId): array
    {
        $this->ensureCanManagePhotos();

        $session = $this->findVisibleSession((int) $this->activeSessionId);
        $photo = $session->items()->whereKey($photoId)->firstOrFail();
        $disk = Storage::disk('local');
        $thumbnailDirectory = $session->storageDirectory().'/.thumbnails';
        $thumbnailPrefix = $thumbnailDirectory.'/'.$photoId.'-';
        $disk->delete($photo->path);
        $disk->delete(array_values(array_filter(
            $disk->files($thumbnailDirectory),
            fn (string $path): bool => str_starts_with($path, $thumbnailPrefix),
        )));
        $photo->delete();
        $this->dispatch('foto-barang-deleted');

        app(AuditLogger::class)->activity(
            'foto_barang_delete',
            "Menghapus foto dari sesi: {$session->judul}",
            auth()->user(),
            ['session_id' => $session->getKey(), 'photo_id' => $photoId],
        );

        Notification::make()->title('Foto dihapus')->success()->send();
        $this->skipRender();

        return ['deleted' => true, 'photo_id' => $photoId];
    }

    /** @return array{deleted: bool, photo_ids: array<int, int>, message?: string} */
    public function deleteSelectedPhotos(
        FotoBarangDeletionService $deletionService,
        array $photoIds,
        string $confirmation,
    ): array {
        $this->ensureCanManagePhotos();

        $this->skipRender();

        if (Str::lower(trim($confirmation)) !== 'hapus') {
            return [
                'deleted' => false,
                'photo_ids' => [],
                'message' => 'Ketik hapus untuk mengonfirmasi penghapusan foto terpilih.',
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
                throw new \RuntimeException('Pilih antara 1 sampai 100 foto untuk dihapus.');
            }

            $session = $this->findVisibleSession((int) $this->activeSessionId);
            $deletedIds = $deletionService->deleteMany($session, $normalizedIds->all());

            app(AuditLogger::class)->activity(
                'foto_barang_bulk_delete',
                'Menghapus '.count($deletedIds)." foto dari sesi: {$session->judul}",
                auth()->user(),
                [
                    'session_id' => $session->getKey(),
                    'photo_ids' => $deletedIds,
                    'photo_count' => count($deletedIds),
                ],
            );

            Notification::make()
                ->title(count($deletedIds).' foto berhasil dihapus')
                ->success()
                ->send();

            return ['deleted' => true, 'photo_ids' => $deletedIds];
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Foto terpilih gagal dihapus')
                ->body('File telah dipulihkan jika proses database tidak berhasil. Silakan coba kembali.')
                ->danger()
                ->send();

            return [
                'deleted' => false,
                'photo_ids' => [],
                'message' => $exception instanceof \RuntimeException
                    ? $exception->getMessage()
                    : 'Penghapusan foto terpilih gagal.',
            ];
        }
    }

    /** @return array{deleted: bool, uuid: string|null} */
    public function deleteSessionFolder(int $sessionId, string $confirmation): array
    {
        $this->ensureCanManagePhotos();

        if (Str::lower(trim($confirmation)) !== 'hapus') {
            Notification::make()->title('Ketik hapus untuk melanjutkan')->danger()->send();

            return ['deleted' => false, 'uuid' => null];
        }

        $session = $this->findVisibleSession($sessionId);

        if ($session->isActive()) {
            Notification::make()
                ->title('Sesi aktif tidak dapat dihapus')
                ->body('Selesaikan sesi terlebih dahulu agar foto yang masih dikirim tetap aman.')
                ->warning()
                ->send();

            return ['deleted' => false, 'uuid' => null];
        }

        $sessionUuid = $session->uuid;
        $sessionTitle = $session->judul;
        $photoCount = $session->items()->count();

        app(AuditLogger::class)->activity(
            'foto_session_delete',
            "Menghapus folder sesi foto: {$sessionTitle}",
            auth()->user(),
            [
                'session_id' => $session->getKey(),
                'session_uuid' => $sessionUuid,
                'photo_count' => $photoCount,
            ],
        );

        $session->delete();

        if ($this->activeSessionId === $sessionId) {
            $this->activeSessionId = null;
            $this->resetSessionForm();
        }

        $this->resetPage('fotoSessionsPage');
        Notification::make()->title('Folder dan seluruh fotonya dihapus')->success()->send();
        $this->skipRender();

        return ['deleted' => true, 'uuid' => $sessionUuid];
    }

    public function retryPhotoProcessing(int $photoId): void
    {
        $this->ensureCanManagePhotos();

        $session = $this->findVisibleSession((int) $this->activeSessionId);
        $photo = $session->items()->whereKey($photoId)->firstOrFail();

        if ($photo->processingCompleted()) {
            Notification::make()->title('Foto sudah selesai diproses')->success()->send();

            return;
        }

        $photo->update([
            'processing_status' => FotoBarangItem::PROCESSING_PENDING,
            'processing_error' => null,
        ]);

        $this->dispatchPhotoProcessing($photo);

        Notification::make()
            ->title('Foto dijadwalkan ulang')
            ->body('File sumber tetap aman selama proses berjalan.')
            ->success()
            ->send();
    }

    public function openSession(int $sessionId): void
    {
        $session = $this->findVisibleSession($sessionId);
        $this->redirect(
            FotoBarangFolder::getUrl(['session' => $session->uuid]),
            navigate: true,
        );
    }

    public function newSession(): void
    {
        $this->ensureCanManagePhotos();

        $current = $this->activeSession();

        if ($current?->isActive()) {
            Notification::make()
                ->title('Selesaikan sesi aktif terlebih dahulu')
                ->warning()
                ->send();

            return;
        }

        $this->activeSessionId = null;
        $this->reset('photo', 'latitude', 'longitude', 'accuracy', 'capturedAt', 'clientCaptureId');
        $this->uploadKey++;
        $this->resetSessionForm();
    }

    public function activeSession(): ?FotoBarangSession
    {
        if ($this->activeSessionId === null) {
            return null;
        }

        return $this->visibleSessionsQuery()
            ->withCount('items')
            ->find($this->activeSessionId);
    }

    /**
     * @return array{enabled: bool, supplier: string|null, options: array<int, string>}
     */
    public function autoLabelContext(): array
    {
        $session = $this->activeSession();
        $user = auth()->user();

        if (! $session?->isActive() || ! $user instanceof User) {
            return ['enabled' => false, 'supplier' => null, 'options' => []];
        }

        $expenses = $session->pengeluaranBelanjas()
            ->with('supplier')
            ->whereHas('kalkulatorBelanja', fn (Builder $query): Builder => $query->visibleTo($user))
            ->get();

        if ($expenses->count() !== 1) {
            return ['enabled' => false, 'supplier' => null, 'options' => []];
        }

        return [
            'enabled' => true,
            'supplier' => $expenses->first()?->namaSupplier(),
            'options' => Barang::query()
                ->orderBy('nama_barang')
                ->get(['id', 'kode_barang', 'nama_barang'])
                ->mapWithKeys(fn (Barang $barang): array => [
                    (int) $barang->getKey() => "{$barang->kode_barang} — {$barang->nama_barang}",
                ])
                ->all(),
        ];
    }

    public function updatedSelectedBarangId(): void
    {
        $context = $this->autoLabelContext();

        if (! $context['enabled'] || ! array_key_exists((int) $this->selectedBarangId, $context['options'])) {
            $this->selectedBarangId = null;
        }
    }

    /** @return LengthAwarePaginator<FotoBarangSession> */
    public function sessions(): LengthAwarePaginator
    {
        $query = $this->visibleSessionsQuery()
            ->with('pengeluaranBelanjas.supplier')
            ->withCount('items');

        if ($this->historyDate !== '' && $this->historyDate !== 'all') {
            $query->whereDate('dimulai_at', $this->historyDate);
        }

        return $query
            ->latest('dimulai_at')
            ->paginate(10, ['*'], 'fotoSessionsPage');
    }

    public function updatedHistoryDate(): void
    {
        $this->resetPage('fotoSessionsPage');
    }

    public function showTodaySessions(): void
    {
        $this->historyDate = now('Asia/Jakarta')->toDateString();
        $this->resetPage('fotoSessionsPage');
    }

    public function showAllSessionDates(): void
    {
        $this->historyDate = 'all';
        $this->resetPage('fotoSessionsPage');
    }

    public function folderUrl(FotoBarangSession $session): string
    {
        $url = FotoBarangFolder::getUrl(['session' => $session->uuid]);
        $query = [
            'tanggal' => $this->historyDate,
        ];
        $page = $this->getPage('fotoSessionsPage');

        if ($page > 1) {
            $query['fotoSessionsPage'] = $page;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format(max(1, $bytes / 1024), 0, ',', '.').' KB';
    }

    private function findVisibleSession(int $sessionId): FotoBarangSession
    {
        return $this->visibleSessionsQuery()->findOrFail($sessionId);
    }

    private function ensureCanManagePhotos(): void
    {
        abort_unless($this->canManagePhotos(), 403);
    }

    private function visibleSessionsQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangSession::query()->visibleTo($user);
    }

    private function visibleExpensesQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return PengeluaranBelanja::query()
            ->whereHas('kalkulatorBelanja', fn (Builder $query): Builder => $query->visibleTo($user));
    }

    private function resetSessionForm(): void
    {
        $this->judul = 'Barang Datang - '.now('Asia/Jakarta')->locale('id')->translatedFormat('d M Y H.i');
        $this->namaLokasi = '';
        $this->alamat = '';
        $this->selectedBarangId = null;
    }

    private function autoAssignCapturedPhoto(
        FotoBarangSession $session,
        FotoBarangItem $photo,
        ?int $barangId,
    ): void {
        if (! $barangId) {
            return;
        }

        try {
            app(FotoBarangPurchaseItemLinkService::class)
                ->autoAssignCapturedPhoto($session, $photo, $barangId);
        } catch (Throwable $exception) {
            // Foto sudah tersimpan. Label otomatis tidak boleh membuat proses
            // pengambilan foto terlihat gagal atau menghentikan sesi kamera.
            report($exception);
        }
    }

    private function captureTime(): CarbonImmutable
    {
        if (blank($this->capturedAt)) {
            return CarbonImmutable::now('Asia/Jakarta');
        }

        try {
            return CarbonImmutable::parse($this->capturedAt)->setTimezone('Asia/Jakarta');
        } catch (Throwable) {
            return CarbonImmutable::now('Asia/Jakarta');
        }
    }

    private function dispatchPhotoProcessing(FotoBarangItem $item): void
    {
        $queue = (string) config('foto_barang.processing_queue', 'default');

        if (config('foto_barang.processing_mode') === 'queue') {
            ProcessFotoBarangImage::dispatch((int) $item->getKey())->onQueue($queue);

            return;
        }

        ProcessFotoBarangImage::dispatchAfterResponse((int) $item->getKey())->onQueue($queue);
    }
}
