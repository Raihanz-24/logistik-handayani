<?php

namespace App\Filament\Pages;

use App\Models\FotoBarangSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FotoBarangImageService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class FotoBarangMaps extends Page
{
    use WithFileUploads;

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

    public int $uploadKey = 0;

    public int $sessionLimit = 20;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->resetSessionForm();

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
        $data = $this->validate([
            'judul' => ['required', 'string', 'max:150'],
            'namaLokasi' => ['required', 'string', 'max:255'],
            'alamat' => ['required', 'string', 'max:1000'],
        ], [
            'judul.required' => 'Nama sesi wajib diisi.',
            'namaLokasi.required' => 'Nama lokasi wajib diisi.',
            'alamat.required' => 'Alamat untuk watermark wajib diisi.',
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
            'nama_lokasi' => $data['namaLokasi'],
            'alamat' => $data['alamat'],
            'status' => FotoBarangSession::STATUS_AKTIF,
            'dimulai_at' => now('Asia/Jakarta'),
        ]);

        $this->activeSessionId = (int) $session->getKey();

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

    public function updatedPhoto(): void
    {
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
                ->body('Foto sudah dipilih. Aktifkan GPS atau gunakan lokasi default Paiton, lalu tekan Proses Foto.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $this->savePhoto();
    }

    public function savePhoto(): void
    {
        $isLiveCapture = filled($this->capturedAt);

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
            $item = app(FotoBarangImageService::class)->store(
                $session,
                $this->photo,
                (float) $this->latitude,
                (float) $this->longitude,
                $this->accuracy,
                $this->captureTime(),
            );

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

            $this->reset('photo', 'capturedAt');
            $this->uploadKey++;
            $this->dispatch('foto-barang-saved');

            Notification::make()
                ->title("Foto {$item->urutan} tersimpan")
                ->body($this->formatBytes($item->ukuran_asli).' dikompres menjadi '.$this->formatBytes($item->ukuran_hasil).'.')
                ->success()
                ->send();

            if ($isLiveCapture) {
                $this->skipRender();
            }
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatch('foto-barang-failed');

            Notification::make()
                ->title('Foto gagal diproses')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            if ($isLiveCapture) {
                $this->skipRender();
            }
        }
    }

    public function updateCoordinates(float $latitude, float $longitude, ?float $accuracy = null): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return;
        }

        $this->latitude = round($latitude, 7);
        $this->longitude = round($longitude, 7);
        $this->accuracy = $accuracy === null ? null : max(0, (int) round($accuracy));
        $this->skipRender();
    }

    public function updateCaptureMetadata(
        float $latitude,
        float $longitude,
        ?float $accuracy,
        string $capturedAt,
    ): void {
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

        $this->skipRender();
    }

    public function useDefaultLocation(): void
    {
        $this->latitude = (float) config('foto_barang.default_latitude');
        $this->longitude = (float) config('foto_barang.default_longitude');
        $this->accuracy = null;

        Notification::make()
            ->title('Lokasi default Paiton digunakan')
            ->body('Pastikan pengambilan foto memang dilakukan di lokasi tersebut.')
            ->warning()
            ->send();
    }

    public function finishSession(): void
    {
        $session = $this->findVisibleSession((int) $this->activeSessionId);

        if (! $session->isActive()) {
            return;
        }

        if (! $session->items()->exists()) {
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
            ['session_id' => $session->getKey(), 'photo_count' => $session->items()->count()],
        );

        Notification::make()
            ->title('Sesi selesai')
            ->body('Folder foto siap diunduh atau dibagikan sebagai laporan.')
            ->success()
            ->send();
    }

    public function deletePhoto(int $photoId): void
    {
        $session = $this->findVisibleSession((int) $this->activeSessionId);

        if (! $session->isActive()) {
            Notification::make()->title('Foto pada sesi selesai tidak dapat dihapus')->warning()->send();

            return;
        }

        $photo = $session->items()->whereKey($photoId)->firstOrFail();
        Storage::disk('local')->delete($photo->path);
        $photo->delete();
        $this->dispatch('foto-barang-deleted');

        app(AuditLogger::class)->activity(
            'foto_barang_delete',
            "Menghapus foto dari sesi: {$session->judul}",
            auth()->user(),
            ['session_id' => $session->getKey(), 'photo_id' => $photoId],
        );

        Notification::make()->title('Foto dihapus')->success()->send();
    }

    public function openSession(int $sessionId): void
    {
        $session = $this->findVisibleSession($sessionId);
        $this->activeSessionId = (int) $session->getKey();
        $this->reset('photo');
        $this->uploadKey++;
    }

    public function newSession(): void
    {
        $current = $this->activeSession();

        if ($current?->isActive()) {
            Notification::make()
                ->title('Selesaikan sesi aktif terlebih dahulu')
                ->warning()
                ->send();

            return;
        }

        $this->activeSessionId = null;
        $this->reset('photo', 'latitude', 'longitude', 'accuracy', 'capturedAt');
        $this->uploadKey++;
        $this->resetSessionForm();
    }

    public function activeSession(): ?FotoBarangSession
    {
        if ($this->activeSessionId === null) {
            return null;
        }

        return $this->visibleSessionsQuery()
            ->with(['items' => fn ($query) => $query->reorder()->latest('urutan')])
            ->withCount('items')
            ->find($this->activeSessionId);
    }

    /** @return Collection<int, FotoBarangSession> */
    public function sessions(): Collection
    {
        return $this->visibleSessionsQuery()
            ->withCount('items')
            ->latest('dimulai_at')
            ->limit(min(200, max(20, $this->sessionLimit)))
            ->get();
    }

    public function totalSessions(): int
    {
        return $this->visibleSessionsQuery()->count();
    }

    public function loadMoreSessions(): void
    {
        $this->sessionLimit = min(200, $this->sessionLimit + 20);
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

    private function visibleSessionsQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangSession::query()->visibleTo($user);
    }

    private function resetSessionForm(): void
    {
        $this->judul = 'Barang Datang - '.now('Asia/Jakarta')->locale('id')->translatedFormat('d M Y H.i');
        $this->namaLokasi = (string) config('foto_barang.default_location_name');
        $this->alamat = (string) config('foto_barang.default_address');
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
}
