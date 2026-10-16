<?php

namespace App\Services\Ai\Tools;

use App\Models\User;

/**
 * Kumpulan tool yang boleh dipakai model, sudah difilter berdasarkan
 * permission user yang login.
 *
 * Tool yang tidak diizinkan TIDAK didaftarkan, sehingga model tidak
 * akan mencoba memanggilnya.
 */
class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    public function __construct(private readonly User $user)
    {
        foreach ($this->definitions() as $tool) {
            try {
                $tool->authorize($user);
            } catch (ToolException) {
                // User tidak berizin — sembunyikan tool ini.
                continue;
            }

            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return array<int, AiTool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @throws ToolException
     */
    public function get(string $name): AiTool
    {
        if (! isset($this->tools[$name])) {
            throw new ToolException('Tool tidak tersedia atau tidak diizinkan: '.$name);
        }

        return $this->tools[$name];
    }

    /**
     * Daftar tool dalam format OpenAI `tools`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOpenAiTools(): array
    {
        return array_map(
            fn (AiTool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ],
            $this->all(),
        );
    }

    /**
     * Definisi tool. Read-only + tool tulis (yang hanya menyiapkan draft).
     *
     * @return array<int, AiTool>
     */
    private function definitions(): array
    {
        return [
            app(CariBarangTool::class),
            app(CekStokTool::class),
            app(CariLokasiTool::class),
            app(CariRakTool::class),
            app(CariSupplierTool::class),
            app(SaranBelanjaSupplierTool::class),
            app(BuatDraftMutasiTool::class),
            app(CatatTransaksiBelanjaTool::class),
            app(TambahItemBelanjaTool::class),
        ];
    }
}
