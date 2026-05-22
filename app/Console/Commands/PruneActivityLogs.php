<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use Illuminate\Console\Command;

class PruneActivityLogs extends Command
{
    protected $signature = 'logs:prune {--days=60 : Delete activity logs older than this many days}';

    protected $description = 'Delete old activity log entries.';

    public function handle(ActivityLogService $activityLogs): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = $activityLogs->pruneOlderThan($days);

        $this->info("Deleted {$deleted} activity log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
