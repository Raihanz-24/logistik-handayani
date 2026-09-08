<?php

namespace App\Http\Controllers;

use App\Models\BackupRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, BackupRecord $backup, string $file): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('super_admin'), 403);
        abort_unless($backup->status === BackupRecord::STATUS_COMPLETED, 404);

        $path = match ($file) {
            'database' => $backup->database_path,
            'files' => $backup->files_path,
            default => null,
        };
        abort_unless(filled($path) && Storage::disk('local')->exists($path), 404);

        app(AuditLogger::class)->activity(
            'backup_download',
            'Mengunduh file backup '.($file === 'database' ? 'database' : 'lampiran').'.',
            $user,
            ['backup_record_id' => $backup->getKey(), 'file' => $file],
        );

        return Storage::disk('local')->download(
            $path,
            $file === 'database'
                ? 'backup-database-'.$backup->created_at->format('Ymd-His').'.sql.gz'
                : 'backup-file-'.$backup->created_at->format('Ymd-His').'.zip',
        );
    }
}
