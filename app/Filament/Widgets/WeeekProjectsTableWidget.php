<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Tables\Columns\TextColumn;

class WeeekProjectsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Проекты Weeek';

    protected int | string | array $columnSpan = 'full';

    protected function rows(): array
    {
        return Project::query()
            ->whereNotNull('external_id')
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'current_stage' => $project->current_stage,
                'risk_score' => $project->risk_score,
                'client_name' => $project->client?->name ?? '—',
            ])
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Проект')->wrap(),
            TextColumn::make('client_name')->label('Клиент')->wrap(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('current_stage')->label('Стадия')->placeholder('—'),
            TextColumn::make('risk_score')->label('Risk score')->sortable(),
        ];
    }
}
