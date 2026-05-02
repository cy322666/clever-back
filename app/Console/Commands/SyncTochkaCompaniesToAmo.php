<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\RevenueTransaction;
use App\Models\SourceConnection;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use App\Services\Integrations\Connectors\TochkaBankConnector;
use App\Services\Integrations\SourceConnectionBootstrapper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncTochkaCompaniesToAmo extends Command
{
    protected $signature = 'tochka:sync-companies-to-amo
        {--from= : Дата начала выгрузки, по умолчанию начало текущего года}
        {--to= : Дата окончания выгрузки, по умолчанию сегодня}
        {--tag=Точка : Тег для компаний в amoCRM}
        {--from-db : Не ходить в Точку, взять компании и метрики из локальной БД}';

    protected $description = 'Выгрузить контрагентов из Точки и синхронизировать компании в amoCRM по ИНН с тегом.';

    public function handle(
        SourceConnectionBootstrapper $bootstrapper,
        TochkaBankConnector $tochka,
        AmoCrmConnector $amo,
    ): int {
        try {
            $bootstrapper->ensureDefaults();

            $tochkaConnection = $this->connection('tochka');
            $amoConnection = $this->connection('amo');

            if (! $tochkaConnection) {
                $this->error('Не найден включенный источник tochka.');

                return self::FAILURE;
            }

            if (! $amoConnection) {
                $this->error('Не найден включенный источник amo.');

                return self::FAILURE;
            }

            $from = $this->dateOption('from')?->startOfDay() ?? CarbonImmutable::now()->startOfYear()->startOfDay();
            $to = $this->dateOption('to')?->endOfDay() ?? CarbonImmutable::now()->endOfDay();
            $tag = trim((string) $this->option('tag')) ?: 'Точка';
            $fromDb = (bool) $this->option('from-db');

            if ($fromDb) {
                $this->info('Беру компании и метрики из локальной БД: '.$from->toDateString().' - '.$to->toDateString());
                [$clients, $clientMetrics] = $this->clientsAndMetricsFromDatabase($from, $to);

                $this->line(sprintf(
                    'БД: компаний %d, компаний с оплатами за период %d.',
                    $clients->count(),
                    count($clientMetrics),
                ));
            } else {
                $this->info('Забираю контрагентов из Точки: '.$from->toDateString().' - '.$to->toDateString());
                $tochkaStats = $tochka->syncCounterparties($tochkaConnection, $from, $to);
                $clients = $tochkaStats['clients'];
                $clientMetrics = $tochkaStats['client_metrics'] ?? [];

                $this->line(sprintf(
                    'Точка: операций %d, новых клиентов %d, обновлено %d, пропущено %d, уникальных контрагентов %d.',
                    $tochkaStats['pulled'],
                    $tochkaStats['created'],
                    $tochkaStats['updated'],
                    $tochkaStats['skipped'],
                    $clients->count(),
                ));
            }

            $this->info('Синхронизирую компании в amoCRM по ИНН и ставлю тег "'.$tag.'"...');
            $amoStats = $amo->syncClientsToAmoByInn(
                $amoConnection,
                $clients,
                $tag,
                clientMetrics: $clientMetrics,
            );

            $this->line(sprintf(
                'amoCRM: обработано %d, создано %d, найдено/обновлено %d, тег поставлен %d, поля LTV/продаж обновлены %d, пропущено %d.',
                $amoStats['pulled'],
                $amoStats['created'],
                $amoStats['updated'],
                $amoStats['tagged'],
                $amoStats['metrics_updated'],
                $amoStats['skipped'],
            ));

            foreach (array_slice($amoStats['warnings'], 0, 20) as $warning) {
                $this->warn($warning);
            }

            if (count($amoStats['warnings']) > 20) {
                $this->warn('Еще предупреждений: '.(count($amoStats['warnings']) - 20));
            }

            $this->info('Синхронизация компаний из Точки завершена.');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function connection(string $sourceKey): ?SourceConnection
    {
        return SourceConnection::query()
            ->where('source_key', $sourceKey)
            ->where('is_enabled', true)
            ->first();
    }

    private function dateOption(string $option): ?CarbonImmutable
    {
        $value = trim((string) $this->option($option));

        return $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @return array{0:\Illuminate\Support\Collection<int, Client>, 1:array<int, array{ltv:float, sales_count:int}>}
     */
    private function clientsAndMetricsFromDatabase(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $clients = Client::query()
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->where(function ($query) {
                $query
                    ->where('source_type', 'amo_company')
                    ->orWhere('source_type', 'tochka')
                    ->orWhereNotNull('metadata->tochka_counterparty');
            })
            ->orderBy('name')
            ->get();

        $metrics = RevenueTransaction::query()
            ->whereNotNull('client_id')
            ->whereBetween('posted_at', [$from, $to])
            ->selectRaw('client_id, coalesce(sum(amount), 0) as ltv, count(*) as sales_count')
            ->groupBy('client_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->client_id => [
                    'ltv' => (float) $row->ltv,
                    'sales_count' => (int) $row->sales_count,
                ],
            ])
            ->all();

        return [$clients, $metrics];
    }
}
