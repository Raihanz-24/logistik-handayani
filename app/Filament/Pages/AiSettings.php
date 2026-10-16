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
            'provider_label' => $setting?->provider_label ?? 'Xcode AI (Lokal)',
            'provider_local_enabled' => $setting?->provider_local_enabled ?? true,
            'provider_local_base_url' => $setting?->provider_local_base_url ?? config('ai.profiles.local.base_url'),
            'provider_local_model' => $setting?->provider_local_model ?? config('ai.profiles.local.model'),
            'provider_local_api_key' => $setting?->provider_local_api_key,
            'training_capture' => $setting?->training_capture ?? (bool) config('ai.training.capture', true),
            'training_local_enabled' => $setting?->training_local_enabled ?? (bool) config('ai.training.local_enabled', false),
            'uploaded_model_path' => $setting?->uploaded_model_path,
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

                Section::make('Provider AI (Xcode AI Lokal)')
                    ->description('Setelan model lokal (OpenAI-compatible: Ollama/vLLM/LM Studio). Tersimpan di aplikasi — TANPA mengubah .env. Kosongkan untuk memakai nilai default. Mengubah driver (cloud/local/auto) tetap lewat .env AI_DRIVER.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('provider_label')
                            ->label('Nama tampilan')
                            ->maxLength(60)
                            ->placeholder('Xcode AI (Lokal)'),
                        Toggle::make('provider_local_enabled')
                            ->label('Profil lokal aktif')
                            ->helperText('Bila dimatikan, mode auto tidak akan mencoba model lokal.')
                            ->default(true),
                        TextInput::make('provider_local_base_url')
                            ->label('Base URL lokal')
                            ->maxLength(200)
                            ->placeholder('http://127.0.0.1:11434/v1')
                            ->helperText('Alamat server model lokal, OpenAI-compatible.'),
                        TextInput::make('provider_local_model')
                            ->label('Nama model lokal')
                            ->maxLength(120)
                            ->placeholder('qwen2.5:14b-instruct'),
                        TextInput::make('provider_local_api_key')
                            ->label('API key lokal (opsional)')
                            ->maxLength(200)
                            ->password()
                            ->revealable()
                            ->helperText('Biasanya "local"/kosong untuk server lokal.'),
                    ]),

                Section::make('Pelatihan Mandiri (Distilasi)')
                    ->description('Rekam contoh perintah→aksi→jawaban untuk fine-tune model di komputer lain. Tidak melatih model di server ini.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Toggle::make('training_capture')
                            ->label('Rekam data pelatihan')
                            ->helperText('Menyimpan contoh ke tabel ai_training_examples. Matikan bila tidak ingin menyimpan.')
                            ->default(true),
                        Toggle::make('training_local_enabled')
                            ->label('Izinkan rekam saat pakai model lokal')
                            ->helperText('Default: nonaktif (server tetap bersih saat memakai Xcode AI lokal).')
                            ->default(false),
                    ]),

                Section::make('Model Lokal yang Dilatih di Komputer Lain')
                    ->description('Setelah Anda fine-tune model di komputer lain, jalankan model itu di server ini (Ollama/vLLM/LM Studio) lalu isi alamat & nama modelnya di bagian "Provider AI" di atas. Kolom di bawah hanya mencatat lokasi/berkas model agar terdokumentasi di aplikasi.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('uploaded_model_path')
                            ->label('Lokasi / nama model hasil latih')
                            ->maxLength(300)
                            ->placeholder('contoh: /opt/models/xcode-ai-ft-v1.gguf atau xcode-ai-ft:latest')
                            ->helperText('Opsional. Catatan agar tim lain tahu model mana yang dipakai. Untuk mengaktifkannya, arahkan "Nama model lokal" ke sini.')
                            ->columnSpanFull(),
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
                'provider_label' => $state['provider_label'] ?: null,
                'provider_local_enabled' => (bool) ($state['provider_local_enabled'] ?? true),
                'provider_local_base_url' => $state['provider_local_base_url'] ?: null,
                'provider_local_model' => $state['provider_local_model'] ?: null,
                'provider_local_api_key' => $state['provider_local_api_key'] ?: null,
                'training_capture' => (bool) ($state['training_capture'] ?? true),
                'training_local_enabled' => (bool) ($state['training_local_enabled'] ?? false),
                'uploaded_model_path' => $state['uploaded_model_path'] ?: null,
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
