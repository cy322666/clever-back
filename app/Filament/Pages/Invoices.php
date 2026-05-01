<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\InvoicesStatsOverviewWidget;
use App\Filament\Widgets\InvoicesTableWidget;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use App\Services\Integrations\SourceConnectionBootstrapper;
use App\Models\SourceConnection;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class Invoices extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static ?string $navigationLabel = 'Счета';
    protected static ?string $title = 'Счета';
    protected static ?int $navigationSort = 4;

    protected function widgets(): array
    {
        return [
            $this->widget(InvoicesStatsOverviewWidget::class, withPeriod: true),
            $this->widget(InvoicesTableWidget::class, withPeriod: true),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncInvoices')
                ->label('Синхронизировать счета')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    app(SourceConnectionBootstrapper::class)->ensureDefaults();
                    $connection = SourceConnection::query()->where('source_key', 'amo')->first();

                    if ($connection) {
                        app(AmoCrmConnector::class)->syncInvoicesOnly($connection);
                    }

                    Notification::make()
                        ->title('Счета синхронизированы')
                        ->success()
                        ->send();
                }),
        ];
    }
}
