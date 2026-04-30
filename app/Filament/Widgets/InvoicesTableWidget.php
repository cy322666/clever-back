<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Services\Analytics\InvoiceAnalyticsService;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use App\Support\AnalyticsPeriod;
use App\Models\SourceConnection;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;

class InvoicesTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Список счетов';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        $period = $this->resolvePeriod();

        return app(InvoiceAnalyticsService::class)->build($period)['rows'] ?? [];
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Счет')->wrap()->searchable(),
            TextColumn::make('customer_name')->label('Клиент')->wrap()->searchable(),
            TextColumn::make('category')->label('Группа')->badge()->placeholder('—'),
            TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            SelectColumn::make('payment_status')
                ->label('Статус оплаты')
                ->options($this->paymentStatusOptions())
                ->updateStateUsing(function ($state, array $record): string {
                    $this->updatePaymentStatus((int) $record['id'], (string) $state);

                    return (string) $state;
                }),
            TextColumn::make('invoice_date')->label('Дата')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),
            IconColumn::make('invoice_link')
                ->label('')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (?string $state) => filled($state) ? 'primary' : 'gray')
                ->tooltip('Открыть счет')
                ->url(fn (?string $state) => $state)
                ->openUrlInNewTab(),
        ];
    }

    protected function paymentStatusOptions(): array
    {
        return [
            'Не указан' => 'Не указан',
            'Создан' => 'Создан',
            'Отправлен' => 'Отправлен',
            'Оплачен' => 'Оплачен',
            'Частично оплачен' => 'Частично оплачен',
            'Отменен' => 'Отменен',
        ];
    }

    protected function updatePaymentStatus(int $invoiceId, string $status): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice) {
            return;
        }

        $sourceConnection = SourceConnection::query()->find($invoice->source_connection_id);

        if ($sourceConnection) {
            app(AmoCrmConnector::class)->updateInvoicePaymentStatus($sourceConnection, $invoice, $status);
        }

        $invoice->update([
            'payment_status' => $status,
        ]);
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('all');
    }
}
