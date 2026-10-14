<?php

namespace App\Filament\Pages;

use App\Models\AiSetting;
use App\Services\Ai\AiClientException;
use App\Services\Ai\DeepSeekClient;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Pengaturan scope & persona Asisten AI (super_admin).
 *
 * Semua teks di sini disuntik ke system prompt. Tujuannya membuat jawaban
 * terarah, akurat, dan hemat token (instruksi ringkas, batasan eksplisit).
 */
class AiSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pengaturan Asisten AI';

    protected static ?string $title = 'Pengaturan Asisten AI';

    protected static ?int $navigationSort = 905;

    protected static ?string $slug = 'asisten-ai-pengaturan';

    protected static string $view = 'filament.pages.ai-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! config('ai.enabled')) {
            Notification::make()
                ->title('Asisten AI nonaktif')
                ->body('Pengaturan tetap bisa diisi, namun halaman Asisten baru aktif setelah AI_ENABLED=true.')
                ->warning()
                ->send();
        }

        $this->form->fill($this->settingsState(AiSetting::current()));
    }

    /** @return array<string, mixed> */
    private function settingsState(?AiSetting $setting): array
    {
        return [
            'enabled' => $setting?->enabled ?? true,
            'assistant_name' => $setting?->assistant_name ?? 'Asisten Logistik',
            'persona' => $setting?->persona,
            'scope_description' => $setting?->scope_description,
            'out_of_scope_message' => $setting?->out_of_scope_message,
            'extra_instructions' => $setting?->extra_instructions,
            'restrict_to_listed_topics' => $setting?->restrict_to_listed_topics ?? false,
            'always_include_rules' => $setting?->always_include_rules ?? true,
            'temperature' => $setting?->temperature,
            'max_tokens' => $setting?->max_tokens,
            'max_tool_iterations' => $setting?->max_tool_iterations,
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Identitas & Persona')
                    ->description('Menentukan siapa asisten Anda dan bagaimana ia berbicara. Ringkas = lebih hemat token.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Asisten aktif')
                            ->helperText('Nonaktifkan untuk mematikan asisten tanpa mengubah .env.')
                            ->default(true)
                            ->columnSpanFull(),
                        TextInput::make('assistant_name')
                            ->label('Nama asisten')
                            ->maxLength(60)
                            ->placeholder('Asisten Logistik'),
                        TextInput::make('temperature')
                            ->label('Kreativitas (temperature)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(2)
                            ->step(0.05)
                            ->placeholder('0.20')
                            ->helperText('Rendah (0–0.3) lebih faktual dan hemat.'),
                        Textarea::make('persona')
                            ->label('Persona / gaya bicara')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('Contoh: Ramah, ringkas, fokus operasional gudang.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Ruang Lingkup (Scope)')
                    ->description('Batasi topik yang boleh dijawab asisten. Ini pengendali utama agar jawaban terarah.')
                    ->columns(['default' => 1])
                    ->schema([
                        Textarea::make('scope_description')
                            ->label('Topik yang didukung')
                            ->rows(4)
                            ->maxLength(2000)
                            ->placeholder("Contoh:\n- Cek stok barang per gudang\n- Membuat draft mutasi masuk/keluar\n- Mencatat transaksi belanja ke supplier")
                            ->helperText('Daftar tugas yang boleh dilakukan asisten.'),
                        Textarea::make('out_of_scope_message')
                            ->label('Pesan saat di luar scope')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('Maaf, saya hanya membantu seputar stok, mutasi, dan belanja gudang.'),
                        Toggle::make('restrict_to_listed_topics')
                            ->label('Tolak permintaan di luar topik di atas')
                            ->helperText('Aktifkan agar asisten menolak topik lain (mis. pertanyaan umum/pribadi).'),
                        Textarea::make('extra_instructions')
                            ->label('Instruksi tambahan')
                            ->rows(4)
                            ->maxLength(2000)
                            ->placeholder("Contoh:\n- Selalu sebut nama gudang lengkap.\n- Satuan galon dalam 'buah'.")
                            ->helperText('Aturan operasional bebas. Gunakan untuk hal yang tidak muat di daftar pengetahuan.'),
                    ]),

                Section::make('Kendali Token & Kinerja')
                    ->description('Kosongkan untuk memakai nilai default dari config/ai.php.')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('max_tokens')
                            ->label('Batas token balasan')
                            ->numeric()
                            ->minValue(64)
                            ->maxValue(8192)
                            ->placeholder('1024'),
                        TextInput::make('max_tool_iterations')
                            ->label('Maks langkah tool')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(12)
                            ->placeholder('6'),
                        Toggle::make('always_include_rules')
                            ->label('Sertakan aturan wajib')
                            ->helperText('Menyertakan entri pengetahuan bertanda "selalu sertakan".'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $state = $this->form->getState();
            $setting = AiSetting::current() ?? new AiSetting;

            $setting->fill([
                'enabled' => (bool) ($state['enabled'] ?? true),
                'assistant_name' => $state['assistant_name'] ?: 'Asisten Logistik',
                'persona' => $state['persona'] ?: null,
                'scope_description' => $state['scope_description'] ?: null,
                'out_of_scope_message' => $state['out_of_scope_message'] ?: null,
                'extra_instructions' => $state['extra_instructions'] ?: null,
                'restrict_to_listed_topics' => (bool) ($state['restrict_to_listed_topics'] ?? false),
                'always_include_rules' => (bool) ($state['always_include_rules'] ?? true),
                'temperature' => $state['temperature'] === null || $state['temperature'] === '' ? null : (float) $state['temperature'],
                'max_tokens' => $state['max_tokens'] === null || $state['max_tokens'] === '' ? null : (int) $state['max_tokens'],
                'max_tool_iterations' => $state['max_tool_iterations'] === null || $state['max_tool_iterations'] === '' ? null : (int) $state['max_tool_iterations'],
            ]);
            $setting->save();

            $this->form->fill($this->settingsState($setting));

            Notification::make()->title('Pengaturan Asisten AI disimpan')->success()->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Gagal menyimpan pengaturan')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Uji koneksi ke gateway AI (memverifikasi base_url/model/key).
     */
    public function testConnection(): void
    {
        try {
            $models = app(DeepSeekClient::class)->models();

            if ($models === []) {
                Notification::make()
                    ->title('Terhubung, tetapi daftar model kosong')
                    ->body('Gateway merespons namun tidak mengembalikan daftar model. Cek AI_MODEL secara manual.')
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Koneksi berhasil')
                ->body('Model tersedia: '.implode(', ', array_slice($models, 0, 10)))
                ->success()
                ->send();
        } catch (AiClientException $exception) {
            Notification::make()
                ->title('Koneksi gagal')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Koneksi gagal')
                ->body('Terjadi kesalahan saat menghubungi layanan AI.')
                ->danger()
                ->send();
        }
    }
}
