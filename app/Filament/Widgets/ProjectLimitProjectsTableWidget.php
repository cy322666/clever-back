<?php

namespace App\Filament\Widgets;

use App\Services\Alerts\ProjectLimitMonitorService;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;

class ProjectLimitProjectsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Проекты за лимитом';

    protected int | string | array $columnSpan = 'full';

    protected function rows(): array
    {
        return app(ProjectLimitMonitorService::class)->build()['projects'] ?? [];
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('project_name')->label('Проект')->wrap(),
            TextColumn::make('client_name')->label('Клиент')->wrap(),
            TextColumn::make('planned_hours')->label('План')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')->sortable(),
            TextColumn::make('spent_hours')->label('Факт')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')->sortable(),
            TextColumn::make('overrun_hours')->label('Сверх плана')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')->sortable(),
            TextColumn::make('utilization_pct')->label('% загрузки')->badge()->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . '%')->color(fn ($state) => (float) $state >= 100 ? 'danger' : ((float) $state >= 85 ? 'warning' : 'success'))->sortable(),
            BadgeColumn::make('signal')
                ->label('Сигнал')
                ->formatStateUsing(fn ($state) => match ($state) {
                    'critical' => 'За лимитом',
                    'warning' => 'На грани',
                    default => 'Ок',
                })
                ->color(fn ($state) => match ($state) {
                    'critical' => 'danger',
                    'warning' => 'warning',
                    default => 'success',
                }),
        ];
    }
}
