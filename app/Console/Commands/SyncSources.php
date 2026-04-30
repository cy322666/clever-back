<?php

namespace App\Console\Commands;

use App\Models\SourceConnection;
use App\Services\Integrations\SourceConnectionBootstrapper;
use App\Services\Integrations\SourceSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncSources extends Command
{
    protected $signature = 'sources:sync {source? : Optional source key} {--all : Sync all enabled sources}';

    protected $description = 'Run source synchronization for amoCRM, Weeek and bank sources.';

    public function handle(SourceSyncService $service, SourceConnectionBootstrapper $bootstrapper): int
    {
        try {
            $this->info('Looking for enabled source connections...');
            $bootstrapper->ensureDefaults();

            if ($this->option('all') || $this->argument('source') === null) {
                $connections = SourceConnection::query()
                    ->where('is_enabled', true)
                    ->whereIn('source_key', array_keys(config('integrations.sources', [])))
                    ->orderBy('id')
                    ->get();

                if ($connections->isEmpty()) {
                    $this->warn('No enabled source connections found for the current account.');
                    $this->warn('Default sources were not created. Check config/integrations.php.');

                    return self::SUCCESS;
                }

                $results = [];

                foreach ($connections as $connection) {
                    $this->info("Syncing {$connection->source_key} ({$connection->name})...");

                    $log = $service->syncConnection($connection);
                    $results[] = ['connection' => $connection, 'log' => $log];

                    $line = sprintf(
                        '[%s] %s -> %s (pulled: %d, created: %d, updated: %d, errors: %d)',
                        $connection->source_key,
                        $connection->name,
                        $log->status,
                        $log->pulled_count,
                        $log->created_count,
                        $log->updated_count,
                        $log->error_count
                    );

                    if ($log->error_count > 0 && filled($log->error_message)) {
                        $line .= ' error: '.$log->error_message;
                    }

                    $log->error_count > 0 ? $this->warn($line) : $this->line($line);
                }

                $successCount = collect($results)->filter(fn ($result) => $result['log']->error_count === 0)->count();
                $this->info("Synced {$successCount} sources successfully.");

                return self::SUCCESS;
            }

            $sourceKey = (string) $this->argument('source');
            $connection = SourceConnection::query()
                ->where('source_key', $sourceKey)
                ->where('is_enabled', true)
                ->first();

            if (! $connection) {
                $this->warn("Enabled source {$sourceKey} was not found.");

                return self::SUCCESS;
            }

            $this->info("Syncing {$connection->source_key} ({$connection->name})...");
            $log = $service->syncConnection($connection);
            $line = sprintf(
                '[%s] %s -> %s (pulled: %d, created: %d, updated: %d, errors: %d)',
                $connection->source_key,
                $connection->name,
                $log->status,
                $log->pulled_count,
                $log->created_count,
                $log->updated_count,
                $log->error_count
            );

            if ($log->error_count > 0 && filled($log->error_message)) {
                $line .= ' error: '.$log->error_message;
            }

            $log->error_count > 0 ? $this->warn($line) : $this->line($line);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->warn($throwable->getMessage());

            return self::SUCCESS;
        }
    }
}
