<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foto_barang_edits')) {
            return;
        }

        Schema::create('foto_barang_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('foto_barang_item_id')
                ->constrained('foto_barang_items')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path')->unique();
            $table->timestamp('waktu_baru')->index();
            $table->timestamps();

            $table->index(['foto_barang_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_barang_edits');
    }
};
