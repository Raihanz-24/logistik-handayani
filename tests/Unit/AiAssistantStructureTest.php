<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Memastikan fondasi Asisten AI aman & tidak menyentuh tabel existing.
 */
class AiAssistantStructureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_config_ai_default_nonaktif_dan_kredensial_dari_env(): void
    {
        $config = (string) file_get_contents($this->root.'/config/ai.php');

        $this->assertStringContainsString("env('AI_ENABLED', false)", $config);
        $this->assertStringContainsString("env('AI_API_KEY')", $config);
        $this->assertStringNotContainsString('sk-', $config, 'Jangan hardcode API key di config.');
    }

    public function test_migrasi_ai_hanya_membuat_tabel_baru(): void
    {
        $migration = (string) file_get_contents(
            $this->root.'/database/migrations/2026_10_11_000000_create_ai_assistant_tables.php',
        );

        $this->assertStringContainsString("Schema::create('ai_conversations'", $migration);
        $this->assertStringContainsString("Schema::create('ai_messages'", $migration);
        $this->assertStringContainsString("Schema::create('ai_pending_actions'", $migration);
        // Tidak boleh mengubah / menyentuh tabel existing.
        $this->assertStringNotContainsString('Schema::table(', $migration);
        $this->assertStringNotContainsString("DB::table('barang_lokasi'", $migration);
        $this->assertStringNotContainsString("DB::table('mutasis'", $migration);
    }

    public function test_tool_terdaftar_read_only_dan_tulis(): void
    {
        $definitions = (string) file_get_contents(
            $this->root.'/app/Services/Ai/Tools/ToolRegistry.php',
        );

        // Read-only
        foreach (['CariBarangTool', 'CekStokTool', 'CariLokasiTool', 'CariRakTool', 'CariSupplierTool'] as $tool) {
            $this->assertStringContainsString($tool, $definitions);
        }

        // Tulis (hanya menyiapkan draft, dikonfirmasi user)
        $this->assertStringContainsString('BuatDraftMutasiTool', $definitions);
        $this->assertStringContainsString('CatatTransaksiBelanjaTool', $definitions);
    }

    public function test_tool_tulis_menyatakan_perlu_konfirmasi(): void
    {
        foreach (['BuatDraftMutasiTool', 'CatatTransaksiBelanjaTool'] as $tool) {
            $source = (string) file_get_contents($this->root.'/app/Services/Ai/Tools/'.$tool.'.php');
            $this->assertStringContainsString('public function isReadOnly(): bool', $source);
            $this->assertStringContainsString('return false;', $source);
            $this->assertStringContainsString('public function requiresConfirmation(): bool', $source);
        }
    }

    public function test_hanya_ai_action_service_yang_menulis_data_aksi(): void
    {
        $service = (string) file_get_contents($this->root.'/app/Services/Ai/AiActionService.php');

        // Semua penulisan memakai service aplikasi yang sudah ada.
        $this->assertStringContainsString('MutasiDataService::class', $service);
        $this->assertStringContainsString('BelanjaTransactionService::class', $service);
        $this->assertStringContainsString("'status' => 'pending'", $service);
    }

    public function test_aturan_tanpa_satuan_tertanam_di_prompt_dan_seeder(): void
    {
        $prompt = (string) file_get_contents($this->root.'/app/Services/Ai/SystemPromptBuilder.php');
        $seeder = (string) file_get_contents($this->root.'/database/seeders/AiAssistantSeeder.php');
        $tool = (string) file_get_contents($this->root.'/app/Services/Ai/Tools/CatatTransaksiBelanjaTool.php');

        // Aturan inti: tanpa satuan → jumlah 1, satuan "-".
        $this->assertStringContainsString('TANPA SATUAN artinya', $prompt);
        $this->assertStringContainsString('satuan = "-"', $prompt);

        // Tersedia juga sebagai entri pengetahuan yang bisa diedit admin.
        $this->assertStringContainsString('Tanpa satuan berarti 1 barang', $seeder);

        // Deskripsi parameter tool menjelaskan pemakaian "-".
        $this->assertStringContainsString('TANPA satuan', $tool);
    }
}
