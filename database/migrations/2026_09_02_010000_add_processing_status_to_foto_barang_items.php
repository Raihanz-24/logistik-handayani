<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foto_barang_items', function (Blueprint $table): void {
            $table->string('processing_status', 20)
                ->default('completed')
                ->after('path')
                ->index();
            $table->unsignedTinyInteger('processing_attempts')
                ->default(0)
                ->after('processing_status');
            $table->text('processing_error')
                ->nullable()
                ->after('processing_attempts');
            $table->timestamp('processed_at')
                ->nullable()
                ->after('processing_error');
        });
    }

    public function down(): void
    {
        Schema::table('foto_barang_items', function (Blueprint $table): void {
            $table->dropIndex(['processing_status']);
            $table->dropColumn([
                'processing_status',
                'processing_attempts',
                'processing_error',
                'processed_at',
            ]);
        });
    }
};
