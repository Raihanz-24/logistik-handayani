<?php

namespace App\Filament\Pages;

use App\Models\BackupRecord;
use App\Models\BackupSetting;
use App\Services\AppBackupService;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use Throwable;

class BackupManagement extends Page implements HasForms
{
    use InteractsWithForms;
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Backup Sistem';

    protected static ?string $title = 'Backup Sistem';

    protected static ?int $navigationSort = 910;

    protected static string $view = 'filament.pages.backup-management';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $setting = BackupSetting::query()->first();
        $this->form->fill([
            'enabled' => $setting?->enabled ?? false,
            'frequency' => $setting?->frequency ?? 'daily',
            'backup_time' => substr((string) ($setting?->backup_time ?? '02:00:00'), 0, 5),
            'weekly_day' => (string) ($setting?->weekly_day ?? 0),
            'monthly_day' => (string) ($setting?->monthly_day ?? 1),
            'include_files' => $setting?->include_files ?? true,
            'keep_count' => $setting?->keep_count ?? 10,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Jadwal backup otomatis')
                    ->description('Backup disimpan privat di server ini. Scheduler cPanel diperlukan agar jadwal berjalan.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Aktifkan backup otomatis')
                            ->helperText('Matikan bila hanya ingin menjalankan Backup Sekarang secara manual.')
                            ->default(false)
                            ->live()
                            ->columnSpanFull(),
                        Select::make('frequency')
                            ->label('Periode')
                            ->options([
                                'daily' => 'Setiap hari',
                                'weekly' => 'Setiap minggu',
                                'monthly' => 'Setiap bulan',
                            ])
                            ->default('daily')
                            ->required()
                            ->live(),
                        TimePicker::make('backup_time')
                            ->label('Jam backup')
                            ->seconds(false)
                            ->default('02:00')
                            ->required(),
                        Select::make('weekly_day')
                            ->label('Hari backup')
                            ->options([
                                0 => 'Minggu',
                                1 => 'Senin',
                                2 => 'Selasa',
                                3 => 'Rabu',
                                4 => 'Kamis',
                                5 => 'Jumat',
                                6 => 'Sabtu',
                            ])
                            ->default(0)
                            ->required()
                            ->visible(fn ($get): bool => $get('frequency') === 'weekly'),
                        Select::make('monthly_day')
                            ->label('Tanggal backup')
                            ->options(collect(range(1, 31))->mapWithKeys(fn (int $day): array => [$day => 'Tanggal '.$day])->all())
                            ->default(1)
                            ->required()
                            ->helperText('Jika tanggal tidak ada pada bulan tersebut, backup berjalan pada hari terakhir bulan itu.')
                            ->visible(fn ($get): bool => $get('frequency') === 'monthly'),
                        Toggle::make('include_files')
                            ->label('Sertakan file gambar')
                            ->helperText('Mencakup Foto Maps, hasil edit, foto barang, dan nota belanja.')
                            ->default(true),
                        TextInput::make('keep_count')
                            ->label('Simpan riwayat terakhir')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(60)
                            ->default(10)
                            ->suffix('backup')
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function saveSettings(): void
    {
        $data = $this->form->getState();
        $setting = BackupSetting::query()->first() ?? new BackupSetting;
        $setting->fill([
            ...$data,
            'weekly_day' => (int) $data['weekly_day'],
            'monthly_day' => (int) $data['monthly_day'],
            'keep_count' => (int) $data['keep_count'],
            'backup_time' => substr((string) $data['backup_time'], 0, 5).':00',
        ]);
        $setting->save();

        app(AuditLogger::class)->activity(
            'backup_settings_update',
            'Memperbarui pengaturan backup otomatis.',
            auth()->user(),
            [
                'enabled' => $setting->enabled,
                'frequency' => $setting->frequency,
                'backup_time' => $setting->backup_time,
                'include_files' => $setting->include_files,
            ],
        );

        Notification::make()
            ->title('Pengaturan backup disimpan')
            ->success()
            ->send();
    }

    public function runNow(AppBackupService $backupService): void
    {
        try {
            $record = $backupService->run(BackupRecord::TYPE_MANUAL, auth()->user());
            $this->resetPage('backupHistoryPage');

            Notification::make()
                ->title('Backup berhasil dibuat')
                ->body('Database dan '.($record->files_path ? 'file lampiran' : 'tanpa file lampiran').' telah disimpan secara privat.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Backup gagal dibuat')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /** @return LengthAwarePaginator<BackupRecord> */
    public function backupRecords(): LengthAwarePaginator
    {
        return BackupRecord::query()
            ->with('creator:id,name,username')
            ->latest('id')
            ->paginate(10, ['*'], 'backupHistoryPage');
    }

    public function scheduleSummary(): string
    {
        $state = $this->data ?? [];

        if (! ($state['enabled'] ?? false)) {
            return 'Backup otomatis belum aktif.';
        }

        $time = (string) ($state['backup_time'] ?? '02:00');

        return match ($state['frequency'] ?? 'daily') {
            'weekly' => 'Setiap '.(['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) ($state['weekly_day'] ?? 0)] ?? 'Minggu').' pukul '.$time.' WIB.',
            'monthly' => 'Setiap tanggal '.(int) ($state['monthly_day'] ?? 1).' pukul '.$time.' WIB.',
            default => 'Setiap hari pukul '.$time.' WIB.',
        };
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    }

    public function nowWib(): string
    {
        return CarbonImmutable::now('Asia/Jakarta')->translatedFormat('d F Y, H:i').' WIB';
    }
}
