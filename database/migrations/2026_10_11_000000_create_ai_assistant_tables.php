<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel untuk Asisten AI (chat & voice).
 *
 * Hanya membuat tabel BARU dengan prefix ai_* — tidak mengubah tabel existing.
 * Aman dijalankan di production karena tidak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            // role: user | assistant | tool | system
            $table->string('role', 16);
            $table->longText('content')->nullable();
            // Nama tool yang dipanggil (untuk role tool / assistant yang memanggil tool)
            $table->string('tool_name')->nullable();
            // ID panggilan tool (untuk memasangkan hasil tool dengan permintaan)
            $table->string('tool_call_id')->nullable();
            // Argumen/permintaan tool (json)
            $table->json('tool_payload')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });

        Schema::create('ai_pending_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('ai_message_id')->nullable()->constrained('ai_messages')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // type: mutasi_draft | pengeluaran_belanja
            $table->string('type', 32);
            // Parameter tervalidasi yang menunggu konfirmasi user (json)
            $table->json('payload');
            // status: pending | confirmed | cancelled | failed
            $table->string('status', 16)->default('pending');
            // Ringkasan singkat untuk ditampilkan di kartu konfirmasi
            $table->string('summary')->nullable();
            // Referensi record hasil setelah dikonfirmasi
            $table->string('result_type', 64)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            // Pesan error bila gagal saat konfirmasi
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['ai_conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
