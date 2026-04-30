<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\IntegrationConnectionsTableWidget;
use App\Services\Integrations\SourceConnectionBootstrapper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class Integrations extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;
    protected static ?string $navigationLabel = 'Источники';
    protected static ?int $navigationSort = 8;

    protected function widgets(): array
    {
        app(SourceConnectionBootstrapper::class)->ensureDefaults();

        return [
            $this->widget(IntegrationConnectionsTableWidget::class),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncAll')
                ->label('Синк всех источников')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    app(\App\Services\Integrations\SourceSyncService::class)->syncAllDetailed();

                    Notification::make()
                        ->title('Синхронизация запущена')
                        ->success()
                        ->send();
                }),
        ];
    }
}
