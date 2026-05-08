<?php

namespace App\Console\Commands;

use App\Services\Invoices\TochkaInvoicePaymentMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MatchTochkaInvoicePayments extends Command
{
    protected $signature = 'invoices:match-tochka-payments
        {--from= : Дата начала периода YYYY-MM-DD}
        {--to= : Дата конца периода YYYY-MM-DD}
        {--paid-status=Оплачен : Статус, который будет установлен в amoCRM}
        {--force : Перепроверить уже обработанные платежи}
        {--dry-run : Только показать статистику без записи и обновления amoCRM}';

    protected $description = 'Match Tochka incoming payments with amoCRM invoices by counterparty INN and amount.';

    public function handle(TochkaInvoicePaymentMatcher $matcher): int
    {
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay()
            : null;
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'))->endOfDay()
            : null;

        $this->info('Сопоставляю оплаты из Точки со счетами amoCRM...');

        if ($from || $to) {
            $this->line('Период: '.($from?->toDateString() ?? 'с начала').' - '.($to?->toDateString() ?? 'сейчас'));
        }

        $stats = $matcher->match(
            from: $from,
            to: $to,
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
            paidStatus: (string) $this->option('paid-status'),
        );

        $this->line(sprintf(
            'Обработано: %d, оплачено: %d, пропущено: %d, неоднозначно: %d, ошибок: %d%s',
            $stats['processed'],
            $stats['applied'],
            $stats['skipped'],
            $stats['ambiguous'],
            $stats['failed'],
            $stats['dry_run'] ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }
}
