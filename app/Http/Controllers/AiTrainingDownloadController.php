<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Ai\AiTrainingExporter;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh dataset pelatihan (distilasi) sebagai JSONL dari UI.
 *
 * Hanya super_admin. Read-only terhadap data transaksi. Berkas dihasilkan
 * on-the-fly lalu dialirkan sebagai unduhan.
 */
class AiTrainingDownloadController extends Controller
{
    public function __invoke(Request $request, AiTrainingExporter $exporter): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('super_admin'), 403);
        abort_unless($exporter->available(), 404, 'Tabel contoh pelatihan belum ada.');

        $onlyCloud = $request->boolean('only_cloud');
        $limit = max(0, (int) $request->query('limit', 0));

        $jsonl = $exporter->toJsonl($onlyCloud, $limit);
        $filename = $exporter->filename($onlyCloud);

        try {
            app(AuditLogger::class)->activity(
                'ai_training_download',
                'Mengunduh dataset pelatihan AI ('.$filename.').',
                $user,
            );
        } catch (\Throwable) {
            // Audit bersifat informatif; jangan gagalkan unduhan.
        }

        return response()->streamDownload(function () use ($jsonl): void {
            echo $jsonl;
        }, $filename, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
        ]);
    }
}
