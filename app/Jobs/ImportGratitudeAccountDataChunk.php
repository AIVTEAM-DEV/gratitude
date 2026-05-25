<?php

namespace App\Jobs;

use App\Services\Import\GratitudeImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ImportGratitudeAccountDataChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public string $status,
        public array $gratitudeIds,
    ) {}

    public function handle(GratitudeImportService $gratitudeImportService): void
    {
        $result = $gratitudeImportService->importAccountDataChunk($this->status, $this->gratitudeIds);
        $status = (int) ($result['status'] ?? 200);

        if ($status >= 400) {
            throw new RuntimeException($result['data']['message'] ?? 'Gratitude account data import chunk failed.');
        }

        Log::info('Gratitude account data import chunk completed.', [
            'import' => 'gratitude',
            'status' => $this->status,
            'account_count' => count($this->gratitudeIds),
            'result' => $result['data'] ?? [],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Gratitude account data import chunk job failed.', [
            'import' => 'gratitude',
            'status' => $this->status,
            'account_count' => count($this->gratitudeIds),
            'gratitude_ids' => $this->gratitudeIds,
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $exception?->getMessage(),
            'exception' => $exception,
        ]);
    }
}
