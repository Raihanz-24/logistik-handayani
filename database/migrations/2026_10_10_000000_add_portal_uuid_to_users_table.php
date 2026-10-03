<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO (Portal ↔ Logistik) — ADDITIVE ONLY.
 *
 * Menambah kolom `users.portal_uuid`:
 * - UUID acak milik Portal; dipakai untuk resolusi `portal_uuid → users.id`
 *   saat callback SSO.
 * - TIDAK mengubah kolom `users` yang sudah ada.
 * - Nullable + unik: user lama tetap valid (NULL), dan tidak boleh ada
 *   dua user memakai UUID sama.
 *
 * Aplikasi Logistik TIDAK pernah menyimpan/mengirim `users.id` ke Portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('portal_uuid')->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['portal_uuid']);
            $table->dropColumn('portal_uuid');
        });
    }
};
