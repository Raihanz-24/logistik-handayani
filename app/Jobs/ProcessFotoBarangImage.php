<?php

namespace App\Jobs;

use App\Models\FotoBarangItem;
use App\Services\FotoBarangImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessFotoBarangImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /** @var array<int, int> */
    public array $backoff = [5, 20, 60];

    public function __construct(public int $photoId) {}

    public function uniqueId(): string
    {
        return 'foto-barang-'.$this->photoId;
    }

    public function handle(FotoBarangImageService $imageService): void
    {
        $item = FotoBarangItem::query()->with('session')->find($this->photoId);

        if (! $item || $item->processingCompleted()) {
            return;
        }

        $item->update([
            'processing_status' => FotoBarangItem::PROCESSING,
            'processing_attempts' => min(255, ((int) $item->processing_attempts) + 1),
            'processing_error' => null,
        ]);

        try {
            $imageService->process($item->fresh(['session']));
        } catch (Throwable $exception) {
            FotoBarangItem::query()->whereKey($this->photoId)->update([
                'processing_status' => FotoBarangItem::PROCESSING_FAILED,
                'processing_error' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        FotoBarangItem::query()
            ->whereKey($this->photoId)
            ->where('processing_status', '!=', FotoBarangItem::PROCESSING_COMPLETED)
            ->update([
                'processing_status' => FotoBarangItem::PROCESSING_FAILED,
                'processing_error' => mb_substr(
                    $exception?->getMessage() ?? 'Proses kompresi tidak dapat diselesaikan.',
                    0,
                    2000,
                ),
                'updated_at' => now(),
            ]);
    }
}
