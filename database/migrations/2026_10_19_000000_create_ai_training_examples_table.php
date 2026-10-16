<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contoh pelatihan untuk distilasi (belajar mandiri Xcode AI dari model cloud).
 *
 * Setiap baris = satu giliran percakapan: perintah pengguna (input), aksi/tool
 * yang dipilih model (actions), dan jawaban akhir (output). Bisa diekspor ke
 * JSONL untuk fine-tune model lokal. Additive & aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_training_examples')) {
            return;
        }

        Schema::create('ai_training_examples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_conversation_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider')->nullable();
            $table->text('input');
            $table->json('actions')->nullable();
            $table->text('output')->nullable();
            $table->boolean('exported')->default(false);
            $table->timestamps();

            $table->index(['exported']);
            $table->index(['provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_examples');
    }
};
