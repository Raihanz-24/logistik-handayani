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
        'provider_label',
        'provider_local_enabled',
        'provider_local_base_url',
        'provider_local_model',
        'provider_local_api_key',
        'training_capture',
        'training_local_enabled',
        'uploaded_model_path',
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
            'provider_local_enabled' => 'boolean',
            'training_capture' => 'boolean',
            'training_local_enabled' => 'boolean',
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
