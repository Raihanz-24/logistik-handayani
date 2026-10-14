<?php

namespace Tests\Feature\Ai;

use App\Filament\Pages\AsistenAi;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Tools\BuatDraftMutasiTool;
use App\Services\Ai\Tools\CatatTransaksiBelanjaTool;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AsistenAiPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function enableAi(): void
    {
        config([
            'ai.enabled' => true,
            'ai.api_key' => 'test-key',
            'ai.base_url' => 'https://api.example.test/v1',
            'ai.model' => 'deepseek-v4-flash',
        ]);
    }

    public function test_halaman_tidak_dapat_diakses_bila_ai_nonaktif(): void
    {
        config(['ai.enabled' => false]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        $this->assertFalse(AsistenAi::canAccess());
    }

    public function test_halaman_dapat_diakses_user_dengan_izin(): void
    {
        $this->enableAi();

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        $this->assertTrue(AsistenAi::canAccess());
    }

    public function test_halaman_tidak_dapat_diakses_tanpa_izin(): void
    {
        $this->enableAi();

        $user = $this->userWithPermissions([]);
        $this->actingAs($user);

        $this->assertFalse(AsistenAi::canAccess());
    }

    public function test_kirim_pesan_menyimpan_percakapan_dan_menampilkan_balasan(): void
    {
        $this->enableAi();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Halo, silakan bertanya.'],
                ]],
            ]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        Livewire::test(AsistenAi::class)
            ->set('message', 'Halo asisten')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Halo, silakan bertanya.')
            ->assertSet('message', '');

        $this->assertSame(1, AiConversation::query()->count());
        $this->assertSame(2, AiMessage::query()->count());
        $this->assertDatabaseHas('ai_messages', [
            'role' => AiMessage::ROLE_USER,
            'content' => 'Halo asisten',
        ]);
    }

    public function test_pesan_kosong_diabaikan(): void
    {
        $this->enableAi();

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        Livewire::test(AsistenAi::class)
            ->set('message', '   ')
            ->call('sendMessage');

        $this->assertSame(0, AiConversation::query()->count());
    }

    public function test_open_conversation_menolak_percakapan_user_lain(): void
    {
        $this->enableAi();

        $owner = $this->userWithPermissions(['view_any_barang']);
        $other = $this->userWithPermissions(['view_any_barang']);

        $conversation = AiConversation::query()->create([
            'user_id' => $owner->getKey(),
            'status' => 'active',
        ]);

        $this->actingAs($other);

        Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->assertSet('conversationId', null);
    }

    public function test_konfirmasi_aksi_melalui_halaman_membuat_draft_mutasi(): void
    {
        $this->enableAi();

        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $supplier = Supplier::query()->create(['nama_supplier' => 'PT Sumber', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        $user = $this->userWithPermissions(['view_any_barang', 'create_mutasi']);
        $this->actingAs($user);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 3,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();

        Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->call('confirmAction', $action->getKey())
            ->assertHasNoErrors();

        $this->assertDatabaseHas('mutasis', ['barang_id' => $barang->getKey(), 'status' => 'pending']);
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
    }

    public function test_tampilan_chat_penuh_dengan_mic_di_kiri_dan_riwayat_drawer(): void
    {
        $this->enableAi();

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        $html = Livewire::test(AsistenAi::class)->html();

        // Mic berada sebelum input di dalam composer (kiri) & tombol kirim setelahnya.
        $posMic = strpos($html, 'id="ai-mic-button"');
        $posInput = strpos($html, 'id="ai-message-input"');
        $posSend = strpos($html, 'class="ai-send"');

        $this->assertNotFalse($posMic);
        $this->assertNotFalse($posInput);
        $this->assertNotFalse($posSend);
        $this->assertTrue($posMic < $posInput, 'Mic harus berada di kiri input.');
        $this->assertTrue($posInput < $posSend, 'Tombol kirim harus berada setelah input.');

        // Konfigurasi suara diteruskan ke JS lewat data-attribute.
        $this->assertStringContainsString('data-ai-lang=', $html);
        $this->assertStringContainsString('data-ai-rate=', $html);

        // Panel riwayat ada sebagai drawer yang dikelola state Livewire (bukan kolom kiri).
        // Drawer SELALU ada di DOM (agar morph tidak mengganti root & memutus Livewire);
        // visibilitas ditoggle lewat kelas is-hidden pada pembungkus .ai-history.
        $this->assertStringContainsString('wire:click="toggleHistory"', $html);
        $this->assertStringContainsString('ai-history__panel', $html);
        $this->assertStringContainsString('ai-history__backdrop', $html);
        $this->assertStringContainsString('wire:click="closeHistory"', $html);
        $this->assertStringContainsString('class="ai-history is-hidden"', $html);

        // Setelah dibuka, kelas is-hidden hilang.
        $historyHtml = Livewire::test(AsistenAi::class)->call('toggleHistory')->html();
        $this->assertStringContainsString('ai-history__panel', $historyHtml);
        $this->assertStringContainsString('ai-history__backdrop', $historyHtml);
        $this->assertStringContainsString('wire:click="closeHistory"', $historyHtml);
        $this->assertStringNotContainsString('class="ai-history is-hidden"', $historyHtml);

        // Indikator merekam tersedia & berada DI ATAS composer.
        $posVoice = strpos($html, 'id="ai-voice-panel"');
        $posComposer = strpos($html, 'class="ai-composer"');

        $this->assertNotFalse($posVoice);
        $this->assertNotFalse($posComposer);
        $this->assertTrue($posVoice < $posComposer, 'Panel merekam harus berada di atas input chat.');
        $this->assertStringContainsString('id="ai-voice-stop"', $html);

        // Identitas chat memakai kredit by kafeinxcode, bukan "Siap membantu Anda".
        $this->assertStringContainsString('by kafeinxcode', $html);
        $this->assertStringNotContainsString('Siap membantu Anda', $html);

        // Tidak ada lagi header chatbot besar di dalam halaman.
        $this->assertStringNotContainsString('Tanya lewat chat atau suara', $html);
    }

    public function test_mic_memiliki_penanganan_kesalahan_dan_sinkronisasi_hasil(): void
    {
        $this->enableAi();

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        $html = Livewire::test(AsistenAi::class)->html();

        // Elemen petunjuk status suara tersedia di markup.
        $this->assertStringContainsString('id="ai-voice-hint"', $html);
        $this->assertStringContainsString('id="ai-voice-panel"', $html);

        // Logika suara ada di modul JS global (tahan navigasi SPA).
        $script = (string) file_get_contents(base_path('resources/js/ai-assistant.js'));

        // Deteksi dukungan browser & konteks aman.
        $this->assertStringContainsString('webkitSpeechRecognition', $script);
        $this->assertStringContainsString('isSecureContext', $script);

        // Penanganan error khas Web Speech API.
        $this->assertStringContainsString("'not-allowed'", $script);
        $this->assertStringContainsString("'audio-capture'", $script);

        // Hasil suara disinkronkan ke properti Livewire (cadangan selain event input).
        $this->assertStringContainsString('Livewire.find(wireId)', $script);
        $this->assertStringContainsString("set('message'", $script);

        // Event dijalankan lewat delegasi agar tidak menumpuk antar navigasi SPA.
        $this->assertStringContainsString("addEventListener('livewire:navigated'", $script);
    }

    public function test_modul_js_asisten_dimuat_secara_global(): void
    {
        // Render hook HEAD_END memuat modul agar tetap hidup saat navigasi SPA.
        $pwa = (string) file_get_contents(resource_path('views/filament/pwa.blade.php'));
        $this->assertStringContainsString("resources/js/ai-assistant.js", $pwa);

        $vite = (string) file_get_contents(base_path('vite.config.js'));
        $this->assertStringContainsString("resources/js/ai-assistant.js", $vite);
    }

    public function test_modul_js_mendeteksi_browser_tak_mendukung_suara(): void
    {
        $script = (string) file_get_contents(base_path('resources/js/ai-assistant.js'));

        // Deteksi Opera (tidak mendukung Web Speech API) & pesan jelas.
        $this->assertStringContainsString('isOpera', $script);
        $this->assertStringContainsString('Opera tidak mendukung input suara', $script);

        // Watchdog bila recognition.start() tidak pernah memulai (mis. Edge).
        $this->assertStringContainsString('voiceWatchdog', $script);
        $this->assertStringContainsString('Mikrofon tidak merespons', $script);

        // Footer kredit disembunyikan di halaman chat.
        $this->assertStringContainsString('ai-page-active', $script);
    }

    public function test_modul_js_mendiagnosis_mikrofon_diam(): void
    {
        $script = (string) file_get_contents(base_path('resources/js/ai-assistant.js'));

        // Pengukur level nyata (AudioContext/Analyser) untuk umpan balik visual.
        $this->assertStringContainsString('createMediaStreamSource', $script);
        $this->assertStringContainsString('getByteTimeDomainData', $script);

        // Diagnosa membedakan: tak ada suara vs suara ada tapi tak dikenali.
        $this->assertStringContainsString('diagnoseEmptyResult', $script);
        $this->assertStringContainsString('Tidak ada suara yang terdeteksi', $script);
        $this->assertStringContainsString('tidak mengenalinya', $script);

        // Pesan perangkat mikrofon (izin/perangkat/sibuk).
        $this->assertStringContainsString('MIC_ERROR_MESSAGES', $script);
        $this->assertStringContainsString('Akses mikrofon ditolak', $script);
    }

    public function test_pengukur_level_dilewati_di_perangkat_mobile(): void
    {
        $script = (string) file_get_contents(base_path('resources/js/ai-assistant.js'));

        // Di Chrome Android, getUserMedia bersamaan SpeechRecognition merebut mic
        // → hasilnya "suara kurang jelas". Harus ada gerbang perangkat.
        $this->assertStringContainsString('shouldMeterVoice', $script);
        $this->assertStringContainsString('pointer: coarse', $script);

        // Gelombang tetap merespons suara lewat event recognition saat meter dilewati.
        $this->assertStringContainsString('onspeechstart', $script);
        $this->assertStringContainsString('bumpVoiceGain', $script);
    }

    public function test_warna_level_suara_terpasang_di_panel_voice(): void
    {
        $html = (string) file_get_contents(resource_path('views/filament/pages/asisten-ai.blade.php'));

        // CSS memakai variabel gain mikrofon yang di-set dari JS untuk amplitudo.
        $this->assertStringContainsString('--ai-mic-gain', $html);
        $this->assertStringContainsString('ai-voice-bar', $html);
    }

    public function test_saran_cepat_mengisi_kotak_pesan(): void
    {
        $this->enableAi();

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->actingAs($user);

        Livewire::test(AsistenAi::class)
            ->call('useSuggestion', 0)
            ->assertSet('message', AsistenAi::SUGGESTIONS[0]);
    }

    /**
     * BOM (byte order mark) di awal file Blade memindahkan atribut wire:id
     * Livewire dari pembungkus <div class="fi-page"> ke <section> di dalamnya.
     * Akibatnya setiap morph (mis. membuka drawer riwayat) mengganti root
     * komponen, memutus Livewire, dan SEMUA tombol di halaman berhenti bekerja.
     * Tes ini mengunci agar berkas terkait tidak kembali memiliki BOM.
     */
    public function test_berkas_asisten_tidak_memiliki_bom(): void
    {
        $paths = [
            resource_path('views/filament/pages/asisten-ai.blade.php'),
            resource_path('views/filament/pages/ai-settings.blade.php'),
            resource_path('views/filament/pwa.blade.php'),
            base_path('resources/js/ai-assistant.js'),
            app_path('Filament/Pages/AsistenAi.php'),
        ];

        foreach ($paths as $path) {
            $this->assertFileExists($path);

            $bytes = file_get_contents($path);
            $this->assertNotSame(
                "\xEF\xBB\xBF",
                substr($bytes, 0, 3),
                "Berkas {$path} dimulai dengan BOM; ini akan memutus Livewire di halaman Asisten AI.",
            );
        }
    }

    public function test_batal_aksi_melalui_halaman(): void
    {
        $this->enableAi();

        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $user = $this->userWithPermissions(['view_any_barang', 'create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $this->actingAs($user);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 5000]],
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();

        Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->call('cancelAction', $action->getKey());

        $this->assertSame(AiPendingAction::STATUS_CANCELLED, $action->fresh()->status);
    }
}
