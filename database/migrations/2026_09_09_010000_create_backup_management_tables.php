<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('frequency', 16)->default('daily');
            $table->time('backup_time')->default('02:00:00');
            $table->unsignedTinyInteger('weekly_day')->default(0);
            $table->unsignedTinyInteger('monthly_day')->default(1);
            $table->boolean('include_files')->default(true);
            $table->unsignedTinyInteger('keep_count')->default(10);
            $table->string('last_scheduled_key', 32)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('running');
            $table->string('database_path')->nullable();
            $table->string('files_path')->nullable();
            $table->unsignedBigInteger('database_size')->default(0);
            $table->unsignedBigInteger('files_size')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_records');
        Schema::dropIfExists('backup_settings');
    }
};
