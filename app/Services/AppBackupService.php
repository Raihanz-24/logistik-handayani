<?php

namespace App\Services;

use App\Models\BackupRecord;
use App\Models\BackupSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class AppBackupService
{
    private const DIRECTORY = 'backups';

    public function run(string $type, ?User $actor = null): BackupRecord
    {
        if (! in_array($type, [BackupRecord::TYPE_MANUAL, BackupRecord::TYPE_SCHEDULED], true)) {
            throw new RuntimeException('Jenis backup tidak valid.');
        }

        $lock = Cache::lock('app-backup-running', 7200);

        if (! $lock->get()) {
            throw new RuntimeException('Backup lain masih berjalan. Tunggu hingga proses sebelumnya selesai.');
        }

        try {
            return $this->runLocked($type, $actor);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(string $type, ?User $actor): BackupRecord
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Backup otomatis saat ini membutuhkan koneksi database MySQL.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi ZIP belum aktif di server.');
        }

        if (! function_exists('gzopen')) {
            throw new RuntimeException('Ekstensi gzip belum aktif di server.');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $setting = BackupSetting::query()->first();
        $includeFiles = $setting?->include_files ?? true;
        $record = BackupRecord::query()->create([
            'created_by' => $actor?->getKey(),
            'type' => $type,
            'status' => BackupRecord::STATUS_RUNNING,
            'started_at' => now('Asia/Jakarta'),
        ]);
        $timestamp = now('Asia/Jakarta')->format('Ymd-His');
        $directory = self::DIRECTORY.'/'.$timestamp.'-'.$record->getKey();
        $databasePath = $directory.'/database.sql.gz';
        $filesPath = $directory.'/files.zip';

        try {
            Storage::disk('local')->makeDirectory($directory);
            $database = $this->dumpDatabase($databasePath);
            $files = $includeFiles ? $this->archiveFiles($filesPath) : ['size' => 0, 'files' => 0];

            $record->update([
                'status' => BackupRecord::STATUS_COMPLETED,
                'database_path' => $databasePath,
                'files_path' => $includeFiles ? $filesPath : null,
                'database_size' => $database['size'],
                'files_size' => $files['size'],
                'metadata' => [
                    'database_tables' => $database['tables'],
                    'archived_files' => $files['files'],
                    'include_files' => $includeFiles,
                ],
                'finished_at' => now('Asia/Jakarta'),
            ]);

            if ($setting) {
                $setting->update(['last_run_at' => now('Asia/Jakarta')]);
            }
            $this->pruneOldBackups((int) ($setting?->keep_count ?? 10));

            app(AuditLogger::class)->activity(
                'backup_completed',
                'Backup '.($type === BackupRecord::TYPE_MANUAL ? 'manual' : 'terjadwal').' berhasil dibuat.',
                $actor,
                [
                    'backup_record_id' => $record->getKey(),
                    'database_size' => $database['size'],
                    'files_size' => $files['size'],
                    'include_files' => $includeFiles,
                ],
            );

            return $record->fresh();
        } catch (Throwable $exception) {
            $this->deleteDirectory($directory);
            $record->update([
                'status' => BackupRecord::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now('Asia/Jakarta'),
            ]);

            app(AuditLogger::class)->activity(
                'backup_failed',
                'Backup '.($type === BackupRecord::TYPE_MANUAL ? 'manual' : 'terjadwal').' gagal dibuat.',
                $actor,
                ['backup_record_id' => $record->getKey()],
            );

            throw $exception;
        }
    }

    /** @return array{size: int, tables: int} */
    private function dumpDatabase(string $relativePath): array
    {
        $absolutePath = Storage::disk('local')->path($relativePath);
        $handle = @gzopen($absolutePath, 'wb9');

        if ($handle === false) {
            throw new RuntimeException('File backup database tidak dapat dibuat.');
        }

        $pdo = DB::connection()->getPdo();
        $tables = [];

        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->beginTransaction();

            $this->writeGzip($handle, "-- Backup Logistik Handayani\n");
            $this->writeGzip($handle, '-- Dibuat: '.now('Asia/Jakarta')->toIso8601String()."\n\n");
            $this->writeGzip($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            $tableRows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
                ->fetchAll(PDO::FETCH_NUM);
            $tables = array_map(fn (array $row): string => (string) $row[0], $tableRows);

            foreach ($tables as $table) {
                $quotedTable = $this->quoteIdentifier($table);
                $createRow = $pdo->query('SHOW CREATE TABLE '.$quotedTable)->fetch(PDO::FETCH_NUM);

                if (! is_array($createRow) || ! isset($createRow[1])) {
                    throw new RuntimeException("Struktur tabel {$table} tidak dapat dibaca.");
                }

                $this->writeGzip($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
                $this->writeGzip($handle, $createRow[1].";\n\n");

                $columns = $pdo->query('DESCRIBE '.$quotedTable)->fetchAll(PDO::FETCH_ASSOC);
                $columnNames = array_map(fn (array $column): string => (string) $column['Field'], $columns);

                if ($columnNames === []) {
                    continue;
                }

                $statement = $pdo->query('SELECT * FROM '.$quotedTable);
                $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), $columnNames));

                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    $values = implode(', ', array_map(
                        fn (string $column): string => $this->sqlValue($pdo, $row[$column] ?? null),
                        $columnNames,
                    ));
                    $this->writeGzip($handle, "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES ({$values});\n");
                }

                $statement->closeCursor();
                $this->writeGzip($handle, "\n");
            }

            $this->writeGzip($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } finally {
            gzclose($handle);
        }

        $size = (int) (Storage::disk('local')->size($relativePath) ?: 0);

        if ($size < 64) {
            throw new RuntimeException('File backup database tidak valid.');
        }

        return ['size' => $size, 'tables' => count($tables)];
    }

    /** @return array{size: int, files: int} */
    private function archiveFiles(string $relativePath): array
    {
        $absolutePath = Storage::disk('local')->path($relativePath);
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Arsip file backup tidak dapat dibuat.');
        }

        try {
            $files = $this->addDirectoryToZip(
                $zip,
                storage_path('app/private'),
                'private',
                [self::DIRECTORY],
            );
            $files += $this->addDirectoryToZip($zip, storage_path('app/public'), 'public');
        } finally {
            $zip->close();
        }

        $size = (int) (Storage::disk('local')->size($relativePath) ?: 0);

        if ($size < 22) {
            throw new RuntimeException('Arsip file backup tidak valid.');
        }

        return ['size' => $size, 'files' => $files];
    }

    /** @param array<int, string> $excludedDirectories */
    private function addDirectoryToZip(ZipArchive $zip, string $source, string $prefix, array $excludedDirectories = []): int
    {
        if (! is_dir($source)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relative = str_replace('\\', '/', substr($absolutePath, strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1));

            if (collect($excludedDirectories)->contains(
                fn (string $directory): bool => $relative === $directory || str_starts_with($relative, $directory.'/'),
            )) {
                continue;
            }

            if (! $zip->addFile($absolutePath, $prefix.'/'.$relative)) {
                throw new RuntimeException('Salah satu file tidak dapat dimasukkan ke arsip backup.');
            }

            $count++;
        }

        return $count;
    }

    private function sqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        $quoted = $pdo->quote((string) $value);

        if ($quoted === false) {
            throw new RuntimeException('Salah satu nilai database tidak dapat diamankan untuk backup.');
        }

        return $quoted;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function writeGzip(mixed $handle, string $contents): void
    {
        if (gzwrite($handle, $contents) === false) {
            throw new RuntimeException('File backup database tidak dapat ditulis.');
        }
    }

    private function pruneOldBackups(int $keepCount): void
    {
        $keepCount = max(1, min(60, $keepCount));
        $recordsQuery = BackupRecord::query()
            ->where('status', BackupRecord::STATUS_COMPLETED)
            ->latest('id');

        // MySQL does not accept OFFSET without LIMIT. Laravel emits such a
        // query when skip() is used alone, so only apply it when records can
        // actually be pruned.
        if ($keepCount > 0) {
            $recordsQuery->skip($keepCount)->take(PHP_INT_MAX);
        }

        $oldRecords = $recordsQuery->get();

        foreach ($oldRecords as $record) {
            foreach ([$record->database_path, $record->files_path] as $path) {
                if (filled($path)) {
                    Storage::disk('local')->delete($path);
                }
            }

            $this->deleteDirectory(dirname((string) $record->database_path));
            $record->delete();
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory !== '' && $directory !== '.') {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
}
