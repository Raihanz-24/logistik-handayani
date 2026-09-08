<?php

namespace App\Console\Commands;

use App\Models\BackupRecord;
use App\Models\BackupSetting;
use App\Services\AppBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunDueBackup extends Command
{
    protected $signature = 'backup:run-due';

    protected $description = 'Menjalankan backup terjadwal jika waktu backup telah tiba.';

    public function handle(AppBackupService $backupService): int
    {
        $now = CarbonImmutable::now('Asia/Jakarta');
        $setting = BackupSetting::query()->first();

        if (! $setting?->isDue($now)) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('app-backup-schedule-claim', 120);

        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $key = $setting->scheduleKey($now);
            $claimed = DB::transaction(function () use ($setting, $key): bool {
                $locked = BackupSetting::query()->lockForUpdate()->find($setting->getKey());

                if (! $locked || $locked->last_scheduled_key === $key) {
                    return false;
                }

                $locked->update(['last_scheduled_key' => $key]);

                return true;
            });

            if (! $claimed) {
                return self::SUCCESS;
            }

            $backupService->run(BackupRecord::TYPE_SCHEDULED);
            $this->info('Backup terjadwal berhasil dibuat.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Backup terjadwal gagal: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
