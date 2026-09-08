<?php

namespace Tests\Unit;

use App\Models\BackupSetting;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BackupManagementFeatureTest extends TestCase
{
    public function test_schedule_recognizes_daily_weekly_and_monthly_rules(): void
    {
        $daily = new BackupSetting([
            'enabled' => true,
            'frequency' => 'daily',
            'backup_time' => '02:30:00',
        ]);
        $this->assertTrue($daily->isDue(CarbonImmutable::parse('2026-09-09 02:30:00', 'Asia/Jakarta')));
        $this->assertFalse($daily->isDue(CarbonImmutable::parse('2026-09-09 02:31:00', 'Asia/Jakarta')));

        $weekly = new BackupSetting([
            'enabled' => true,
            'frequency' => 'weekly',
            'backup_time' => '02:30:00',
            'weekly_day' => 3,
        ]);
        $this->assertTrue($weekly->isDue(CarbonImmutable::parse('2026-09-09 02:30:00', 'Asia/Jakarta')));

        $monthly = new BackupSetting([
            'enabled' => true,
            'frequency' => 'monthly',
            'backup_time' => '02:30:00',
            'monthly_day' => 31,
        ]);
        $this->assertTrue($monthly->isDue(CarbonImmutable::parse('2026-09-30 02:30:00', 'Asia/Jakarta')));
    }

    public function test_backup_feature_is_isolated_and_uses_private_storage(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root.'/database/migrations/2026_09_09_010000_create_backup_management_tables.php');
        $service = (string) file_get_contents($root.'/app/Services/AppBackupService.php');
        $page = (string) file_get_contents($root.'/app/Filament/Pages/BackupManagement.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/BackupDownloadController.php');
        $console = (string) file_get_contents($root.'/routes/console.php');

        $this->assertStringContainsString("Schema::create('backup_settings'", $migration);
        $this->assertStringContainsString("Schema::create('backup_records'", $migration);
        $this->assertStringNotContainsString("Schema::table('barangs'", $migration);
        $this->assertStringNotContainsString("Schema::table('mutasis'", $migration);
        $this->assertStringContainsString("Storage::disk('local')", $service);
        $this->assertStringNotContainsString('mysqldump', $service);
        $this->assertStringContainsString('hasRole(\'super_admin\')', $page);
        $this->assertStringContainsString('public function runNow', $page);
        $this->assertStringContainsString('hasRole(\'super_admin\')', $controller);
        $this->assertStringContainsString("Schedule::command('backup:run-due')", $console);
    }
}
