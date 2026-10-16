<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah pengaturan provider & distilasi yang bisa diubah dari UI (additive).
 *
 * - Kolom provider*: mengeset profil "lokal" (Xcode AI) tanpa menyentuh .env.
 *   Kosong = pakai nilai dari config/ai.php.
 * - local_enabled: mengaktifkan pemakaian profil lokal saat AI_DRIVER=auto.
 * - training_local_enabled: izin merekam data pelatihan walau driver = local
 *   (default false: saat pakai lokal, tidak menyimpan data latih di server).
 *
 * Aman: setiap kolom ditambahkan hanya bila belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_settings', 'provider_label')) {
                $table->string('provider_label')->nullable()->after('enabled');
            }

            if (! Schema::hasColumn('ai_settings', 'provider_local_enabled')) {
                $table->boolean('provider_local_enabled')->default(true)->after('provider_label');
            }

            if (! Schema::hasColumn('ai_settings', 'provider_local_base_url')) {
                $table->string('provider_local_base_url')->nullable()->after('provider_local_enabled');
            }

            if (! Schema::hasColumn('ai_settings', 'provider_local_model')) {
                $table->string('provider_local_model')->nullable()->after('provider_local_base_url');
            }

            if (! Schema::hasColumn('ai_settings', 'provider_local_api_key')) {
                $table->string('provider_local_api_key')->nullable()->after('provider_local_model');
            }

            if (! Schema::hasColumn('ai_settings', 'training_capture')) {
                $table->boolean('training_capture')->default(true)->after('provider_local_api_key');
            }

            if (! Schema::hasColumn('ai_settings', 'training_local_enabled')) {
                $table->boolean('training_local_enabled')->default(false)->after('training_capture');
            }

            if (! Schema::hasColumn('ai_settings', 'uploaded_model_path')) {
                $table->string('uploaded_model_path')->nullable()->after('training_local_enabled');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        foreach ([
            'provider_label',
            'provider_local_enabled',
            'provider_local_base_url',
            'provider_local_model',
            'provider_local_api_key',
            'training_capture',
            'training_local_enabled',
            'uploaded_model_path',
        ] as $column) {
            if (Schema::hasColumn('ai_settings', $column)) {
                Schema::table('ai_settings', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
