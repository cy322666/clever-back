<?php

namespace App\Console\Commands;

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
        {--tag=Точка : Тег для компаний в amoCRM}';

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

            $this->info('Забираю контрагентов из Точки: '.$from->toDateString().' - '.$to->toDateString());
            $tochkaStats = $tochka->syncCounterparties($tochkaConnection, $from, $to);
            $clients = $tochkaStats['clients'];

            $this->line(sprintf(
                'Точка: операций %d, новых клиентов %d, обновлено %d, пропущено %d, уникальных контрагентов %d.',
                $tochkaStats['pulled'],
                $tochkaStats['created'],
                $tochkaStats['updated'],
                $tochkaStats['skipped'],
                $clients->count(),
            ));

            $this->info('Синхронизирую компании в amoCRM по ИНН и ставлю тег "'.$tag.'"...');
            $amoStats = $amo->syncClientsToAmoByInn($amoConnection, $clients, $tag);

            $this->line(sprintf(
                'amoCRM: обработано %d, создано %d, найдено/обновлено %d, тег поставлен %d, пропущено %d.',
                $amoStats['pulled'],
                $amoStats['created'],
                $amoStats['updated'],
                $amoStats['tagged'],
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
}
