<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertDetectorService;
use App\Services\Analytics\MetricSnapshotService;
use App\Services\Integrations\SourceSyncService;
use Illuminate\Console\Command;

class SeedDemoDashboard extends Command
{
    protected $signature = 'app:seed-demo-dashboard {--force : Seed even if demo data already exists}';

    protected $description = 'Populate the database with realistic demo data for the owner analytics dashboard.';

    public function handle(): int
    {
        $this->info('Seeding demo data...');
        $this->call('db:seed', [
            '--class' => \Database\Seeders\DatabaseSeeder::class,
        ]);

        $this->info('Running source sync...');
        app(SourceSyncService::class)->syncAll();

        $this->info('Refreshing analytics snapshots...');
        $saved = app(MetricSnapshotService::class)->refresh();

        $this->info('Detecting alerts...');
        $alerts = app(AlertDetectorService::class)->detect();

        $this->info("Demo dataset seeded. Metric snapshots: {$saved}. Alerts detected: {$alerts}.");

        return self::SUCCESS;
    }
}
