<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 50)->nullable()->after('name')->unique();
        });

        DB::table('users')->orderBy('id')->get(['id', 'name', 'email'])->each(function (object $user): void {
            $emailPrefix = Str::before((string) $user->email, '@');
            $base = Str::lower(trim($emailPrefix));
            $base = preg_replace('/[^a-z0-9._-]+/', '_', $base) ?: '';
            $base = trim($base, '._-');
            $base = Str::limit($base !== '' ? $base : 'user'.$user->id, 40, '');
            $username = $base;
            $suffix = 1;

            while (DB::table('users')->where('username', $username)->exists()) {
                $suffixText = '-'.$suffix++;
                $username = Str::limit($base, 50 - strlen($suffixText), '').$suffixText;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
