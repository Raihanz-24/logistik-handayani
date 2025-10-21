<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mutasis', function (Blueprint $table) {
            $table->string('no_ref')->nullable()->after('keterangan');
            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending')->after('no_ref');
            $table->foreignId('created_by')->nullable()->constrained('users')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mutasis', function (Blueprint $table) {
            $table->dropColumn(['no_ref', 'status']);
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');

        });
    }
};
