<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertDetectorService;
use App\Services\Analytics\MetricSnapshotService;
use Illuminate\Console\Command;
use Throwable;

class RefreshAnalyticsSnapshots extends Command
{
    protected $signature = 'analytics:refresh-snapshots';

    protected $description = 'Refresh metric snapshots and regenerate alerts.';

    public function handle(): int
    {
        try {
            $saved = app(MetricSnapshotService::class)->refresh();
            $alerts = app(AlertDetectorService::class)->detect();
        } catch (Throwable $throwable) {
            $this->warn($throwable->getMessage());

            return self::SUCCESS;
        }

        $this->info("Snapshots refreshed: {$saved}; alerts detected: {$alerts}");

        return self::SUCCESS;
    }
}
