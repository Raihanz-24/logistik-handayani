<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Basis pengetahuan Asisten AI.
 *
 * Setiap entri mengajarkan asisten tentang satu hal: fakta (faq), aturan
 * (rule), instruksi (instruction), istilah internal (glossary), atau batasan
 * (restriction). Disuntik ke prompt secara selektif berdasarkan kata kunci
 * agar hemat token.
 */
class AiKnowledgeEntry extends Model
{
    public const TYPE_FAQ = 'faq';

    public const TYPE_RULE = 'rule';

    public const TYPE_INSTRUCTION = 'instruction';

    public const TYPE_GLOSSARY = 'glossary';

    public const TYPE_RESTRICTION = 'restriction';

    protected $fillable = [
        'type',
        'title',
        'keywords',
        'content',
        'action_hint',
        'priority',
        'always_include',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'priority' => 'integer',
            'always_include' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_FAQ => 'FAQ / Fakta',
            self::TYPE_RULE => 'Aturan',
            self::TYPE_INSTRUCTION => 'Instruksi',
            self::TYPE_GLOSSARY => 'Istilah / Glosarium',
            self::TYPE_RESTRICTION => 'Batasan / Larangan',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
