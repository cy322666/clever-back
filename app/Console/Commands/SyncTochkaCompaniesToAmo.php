<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\BankStatementRow;
use App\Models\RevenueTransaction;
use App\Models\SourceConnection;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use App\Services\Integrations\Connectors\TochkaBankConnector;
use App\Services\Integrations\SourceConnectionBootstrapper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $clientsByInn = $clients
            ->mapWithKeys(fn (Client $client): array => [$this->normalizeInn($client->inn) => $client])
            ->filter(fn (?Client $client, ?string $inn): bool => filled($inn) && $client !== null);

        $metrics = [];
        $clientIds = $clients->pluck('id')->all();

        if ($clientIds !== []) {
            RevenueTransaction::query()
                ->leftJoin('bank_statement_rows', 'bank_statement_rows.id', '=', 'revenue_transactions.bank_statement_row_id')
                ->leftJoin('data_import_batches', 'data_import_batches.id', '=', 'bank_statement_rows.data_import_batch_id')
                ->whereIn('revenue_transactions.client_id', $clientIds)
                ->whereBetween('revenue_transactions.transaction_date', [$from, $to])
                ->where(function ($query): void {
                    $query
                        ->where('revenue_transactions.source_system', 'tochka')
                        ->orWhere('bank_statement_rows.source_key', 'tochka')
                        ->orWhere('data_import_batches.source_type', 'tochka');
                })
                ->groupBy('revenue_transactions.client_id')
                ->get([
                    DB::raw('revenue_transactions.client_id as client_id'),
                    DB::raw('coalesce(sum(revenue_transactions.amount), 0) as ltv'),
                    DB::raw('count(*) as sales_count'),
                ])
                ->each(function ($row) use (&$metrics): void {
                    $clientId = (int) $row->client_id;

                    $metrics[$clientId] = [
                        'ltv' => (float) $row->ltv,
                        'sales_count' => (int) $row->sales_count,
                    ];
                });
        }

        BankStatementRow::query()
            ->leftJoin('revenue_transactions', 'revenue_transactions.bank_statement_row_id', '=', 'bank_statement_rows.id')
            ->leftJoin('data_import_batches', 'data_import_batches.id', '=', 'bank_statement_rows.data_import_batch_id')
            ->whereNull('revenue_transactions.id')
            ->where('bank_statement_rows.direction', 'in')
            ->whereBetween('bank_statement_rows.occurred_at', [$from, $to])
            ->where(function ($query): void {
                $query
                    ->where('bank_statement_rows.source_key', 'tochka')
                    ->orWhere('data_import_batches.source_type', 'tochka');
            })
            ->orderBy('bank_statement_rows.id')
            ->get([
                'bank_statement_rows.id',
                'bank_statement_rows.amount',
                'bank_statement_rows.direction',
                'bank_statement_rows.raw_payload',
            ])
            ->each(function (BankStatementRow $row) use (&$metrics, $clientsByInn): void {
                $inn = $this->counterpartyInnFromBankRow($row);
                $client = $inn !== null ? $clientsByInn->get($inn) : null;

                if (! $client) {
                    return;
                }

                $metrics[$client->id] ??= ['ltv' => 0.0, 'sales_count' => 0];
                $metrics[$client->id]['ltv'] += (float) $row->amount;
                $metrics[$client->id]['sales_count']++;
            });

        return [$clients, $metrics];
    }

    private function counterpartyInnFromBankRow(BankStatementRow $row): ?string
    {
        $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
        $party = data_get($payload, 'DebtorParty', []);

        return $this->normalizeInn($this->findValueByKeys(is_array($party) ? $party : [], [
            'inn',
            'инн',
            'innkio',
            'taxid',
            'tax_id',
            'taxnumber',
            'tax_number',
            'taxidentificationnumber',
            'tax_identification_number',
            'payerinn',
            'payer_inn',
            'recipientinn',
            'recipient_inn',
            'counterpartyinn',
            'counterparty_inn',
            'debtorinn',
            'debtor_inn',
            'creditorinn',
            'creditor_inn',
        ]));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function findValueByKeys(mixed $payload, array $keys): mixed
    {
        if (! is_array($payload)) {
            return null;
        }

        $normalizedKeys = collect($keys)
            ->map(fn (string $key): string => $this->normalizeKey($key))
            ->all();

        foreach ($payload as $key => $value) {
            if (in_array($this->normalizeKey((string) $key), $normalizedKeys, true)) {
                return $value;
            }

            if (is_array($value)) {
                $nested = $this->findValueByKeys($value, $keys);

                if ($nested !== null && $nested !== '') {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return str_replace(['_', '-', ' '], '', mb_strtolower($key));
    }

    private function normalizeInn(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return in_array(strlen($digits), [10, 12], true) ? $digits : null;
    }
}
