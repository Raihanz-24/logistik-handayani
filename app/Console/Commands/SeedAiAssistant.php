<?php

namespace App\Console\Commands;

use Database\Seeders\AiAssistantSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pintasan menjalankan seeder Asisten AI tanpa perlu mengetik namespace
 * (sering salah escaping backslash di shell, mis. "DatabaseSeeder\AiAssistantSeeder").
 *
 * Idempotent: seeder memakai firstOrCreate/updateOrCreate, jadi aman diulang.
 */
class SeedAiAssistant extends Command
{
    protected $signature = 'ai:seed';

    protected $description = 'Mengisi pengaturan & pengetahuan awal Asisten AI (aman dijalankan berulang).';

    public function handle(): int
    {
        try {
            $this->call('db:seed', [
                '--class' => AiAssistantSeeder::class,
                '--force' => true,
            ]);

            $this->info('Seeder Asisten AI selesai.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Seeder Asisten AI gagal: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
