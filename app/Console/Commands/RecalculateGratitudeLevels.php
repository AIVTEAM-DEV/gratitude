<?php

namespace App\Console\Commands;

use App\Services\Gratitude\TierService;
use Illuminate\Console\Command;

class RecalculateGratitudeLevels extends Command
{
    protected $signature = 'gratitude:recalculate-levels';

    protected $description = 'Reviews Gratitude level cycles and recalculates automatic levels.';

    public function handle(TierService $tierService): int
    {
        $this->info('Starting Gratitude level cycle checks...');

        $count = $tierService->recalculateDueCycles();

        $this->info("Successfully reviewed {$count} Gratitude accounts.");

        return self::SUCCESS;
    }
}
