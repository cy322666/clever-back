<?php

namespace App\Services\Integrations;

use App\Models\SourceConnection;
use App\Models\SourceSyncLog;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use App\Services\Integrations\Connectors\BankImportConnector;
use App\Services\Integrations\Connectors\TochkaBankConnector;
use App\Services\Integrations\Connectors\WeeekConnector;
use Throwable;

class SourceSyncService
{
    public function __construct(
        protected SourceConnectionBootstrapper $bootstrapper,
    ) {}

    public function syncConnection(SourceConnection $connection): SourceSyncLog
    {
        $this->bootstrapper->ensureDefaults();
        $connection->refresh();
        $log = SourceSyncLog::query()->create([
            'source_connection_id' => $connection->id,
            'source_key' => $connection->source_key,
            'source_type' => $connection->driver,
            'status' => 'running',
            'started_at' => now(),
            'payload' => ['driver' => $connection->driver],
        ]);

        try {
            $connector = $this->connectorFor($connection->driver);
            $result = $connector->sync($connection);

            $log->update([
                'status' => $result->success ? 'success' : 'failed',
                'finished_at' => now(),
                'pulled_count' => $result->pulled,
                'created_count' => $result->created,
                'updated_count' => $result->updated,
                'error_count' => $result->errors,
                'error_message' => $result->message,
                'payload' => array_merge($log->payload ?? [], $result->payload),
            ]);

            $connection->update([
                'status' => $result->success ? 'active' : 'error',
                'last_synced_at' => now(),
                'last_error_at' => $result->success ? null : now(),
                'last_error_message' => $result->success ? null : $result->message,
            ]);
        } catch (Throwable $throwable) {
            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_count' => 1,
                'error_message' => $throwable->getMessage(),
            ]);

            $connection->update([
                'status' => 'error',
                'last_error_at' => now(),
                'last_error_message' => $throwable->getMessage(),
            ]);
        }

        return $log->refresh();
    }

    public function syncAll(): int
    {
        $this->bootstrapper->ensureDefaults();

        return SourceConnection::query()
            ->where('is_enabled', true)
            ->whereIn('source_key', $this->allowedSourceKeys())
            ->get()
            ->sortBy(fn (SourceConnection $connection): int => $this->sourceOrder($connection->source_key))
            ->sum(fn (SourceConnection $connection) => $this->syncConnection($connection)->error_count === 0 ? 1 : 0);
    }

    /**
     * @return array<int, array{connection: SourceConnection, log: SourceSyncLog}>
     */
    public function syncAllDetailed(): array
    {
        $this->bootstrapper->ensureDefaults();

        $connections = SourceConnection::query()
            ->where('is_enabled', true)
            ->whereIn('source_key', $this->allowedSourceKeys())
            ->get()
            ->sortBy(fn (SourceConnection $connection): int => $this->sourceOrder($connection->source_key))
            ->values();

        $results = [];

        foreach ($connections as $connection) {
            $results[] = [
                'connection' => $connection,
                'log' => $this->syncConnection($connection),
            ];
        }

        return $results;
    }

    protected function connectorFor(string $driver)
    {
        $connectors = [
            app(AmoCrmConnector::class),
            app(TochkaBankConnector::class),
            app(BankImportConnector::class),
            app(WeeekConnector::class),
        ];

        foreach ($connectors as $connector) {
            if ($connector->supports($driver)) {
                return $connector;
            }
        }

        throw new \RuntimeException("No connector registered for driver {$driver}");
    }

    /**
     * @return array<int, string>
     */
    protected function allowedSourceKeys(): array
    {
        return array_keys(config('integrations.sources', []));
    }

    protected function sourceOrder(string $sourceKey): int
    {
        return [
            'tochka' => 10,
            'amo' => 20,
            'weeek' => 30,
            'bank' => 40,
        ][$sourceKey] ?? 100;
    }
}
