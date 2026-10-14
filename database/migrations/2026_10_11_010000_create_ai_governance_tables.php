<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pendukung tata kelola Asisten AI:
 * - ai_usages            : rekap pemakaian token per permintaan.
 * - ai_settings          : definisi scope/persona asisten (singleton).
 * - ai_knowledge_entries : basis pengetahuan (aturan/FAQ) agar jawaban terarah.
 *
 * Hanya membuat tabel BARU (prefix ai_) — tidak mengubah tabel existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')->nullable()
                ->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('tool_calls')->default(0);
            $table->unsignedInteger('iterations')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 16)->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['created_at']);
        });

        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('assistant_name')->nullable();
            $table->text('persona')->nullable();
            $table->text('scope_description')->nullable();
            $table->text('out_of_scope_message')->nullable();
            $table->text('extra_instructions')->nullable();
            $table->boolean('restrict_to_listed_topics')->default(false);
            $table->boolean('always_include_rules')->default(true);
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->unsignedInteger('max_tool_iterations')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_knowledge_entries', function (Blueprint $table): void {
            $table->id();
            // faq | rule | instruction | glossary | restriction
            $table->string('type', 32)->default('faq');
            $table->string('title')->nullable();
            // Kata kunci pemicu (array string) agar entri hanya disuntik saat relevan.
            $table->json('keywords')->nullable();
            $table->text('content');
            // Petunjuk tindakan, mis. "gunakan tool buat_draft_mutasi".
            $table->text('action_hint')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('always_include')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_entries');
        Schema::dropIfExists('ai_settings');
        Schema::dropIfExists('ai_usages');
    }
};
