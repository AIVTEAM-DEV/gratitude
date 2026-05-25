<?php

namespace App\Console\Commands;

use App\Models\Gratitude\Gratitude;
use App\Services\Gratitude\PointService;
use App\Services\Gratitude\TierService;
use Illuminate\Console\Command;

class RunGratitudeDailyMaintenance extends Command
{
    protected $signature = 'gratitude:daily-maintenance';

    protected $description = 'Runs daily Gratitude point expiry, activation, level cycle, and inactivity maintenance.';

    public function handle(PointService $pointService, TierService $tierService): int
    {
        $this->info('Starting Gratitude daily maintenance...');

        $activated = $pointService->activateTierPoints();
        $this->info("Activated {$activated} pending point batches.");

        $expired = $pointService->expirePoints();
        $this->info("Expired {$expired['earned']} earned point records and {$expired['bonus']} bonus point records.");

        $reviewed = $tierService->recalculateDueCycles();
        $this->info("Reviewed {$reviewed} Gratitude level cycles.");

        $inactiveCount = 0;
        Gratitude::whereNotNull('gratitudeNumber')
            ->select('id', 'gratitudeNumber')
            ->orderBy('id')
            ->chunkById(100, function ($gratitudes) use ($tierService, &$inactiveCount) {
                foreach ($gratitudes as $gratitude) {
                    if ($tierService->checkInactivity($gratitude->gratitudeNumber)) {
                        $inactiveCount++;
                    }
                }
            });
        $this->info("Flagged {$inactiveCount} inactive Gratitude accounts.");

        $this->info('Gratitude daily maintenance complete.');

        return self::SUCCESS;
    }
}
