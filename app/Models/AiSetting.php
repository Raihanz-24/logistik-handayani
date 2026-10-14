<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan Asisten AI (singleton, baris pertama).
 *
 * Menentukan scope/persona agar jawaban terarah dan hemat token:
 * - persona & scope_description selalu disuntik ke system prompt.
 * - aturan (ai_knowledge_entries) disuntik selektif berdasarkan kata kunci.
 */
class AiSetting extends Model
{
    protected $fillable = [
        'enabled',
        'assistant_name',
        'persona',
        'scope_description',
        'out_of_scope_message',
        'extra_instructions',
        'restrict_to_listed_topics',
        'always_include_rules',
        'temperature',
        'max_tokens',
        'max_tool_iterations',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'restrict_to_listed_topics' => 'boolean',
            'always_include_rules' => 'boolean',
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'max_tool_iterations' => 'integer',
        ];
    }

    /**
     * Ambil pengaturan tunggal (dibuat otomatis bila belum ada).
     * Mengembalikan null bila tabel belum dimigrasi (fail-safe).
     */
    public static function current(): ?self
    {
        if (! Schema::hasTable('ai_settings')) {
            return null;
        }

        return static::query()->firstOrCreate([], [
            'enabled' => true,
            'assistant_name' => 'Asisten Logistik',
        ]);
    }
}
