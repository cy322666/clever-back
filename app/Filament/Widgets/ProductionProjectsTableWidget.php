<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Services\Analytics\ProductionAnalyticsService;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;

class ProductionProjectsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Проекты';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public ?string $projectTypePreset = null;

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        $period = $this->resolvePeriod();

        return app(ProductionAnalyticsService::class)->build($period)['project_summary']?->filter(
            fn (array $row) => ($row['project_status'] ?? null) === 'active'
        )->when(
            filled($this->projectTypePreset),
            fn ($rows) => $rows->filter(fn (array $row) => ($row['project_type'] ?? null) === $this->projectTypePreset)
        )->map(function (array $row): array {
            $row['__key'] = (string) $row['project_id'];

            return $row;
        })->values()->all() ?? [];
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('allTypes')
                ->label('Все типы')
                ->button()
                ->outlined(filled($this->projectTypePreset))
                ->color(filled($this->projectTypePreset) ? 'gray' : 'primary')
                ->action(function (): void {
                    $this->setProjectTypePreset(null);
                }),
            Action::make('hourly_until_date')
                ->label('Почасовка')
                ->button()
                ->outlined($this->projectTypePreset !== 'hourly_until_date')
                ->color($this->projectTypePreset === 'hourly_until_date' ? 'primary' : 'gray')
                ->action(function (): void {
                    $this->setProjectTypePreset('hourly_until_date');
                }),
            Action::make('hourly_package')
                ->label('Почасовка пакетная')
                ->button()
                ->outlined($this->projectTypePreset !== 'hourly_package')
                ->color($this->projectTypePreset === 'hourly_package' ? 'primary' : 'gray')
                ->action(function (): void {
                    $this->setProjectTypePreset('hourly_package');
                }),
            Action::make('support_monthly')
                ->label('Ежемесячная')
                ->button()
                ->outlined($this->projectTypePreset !== 'support_monthly')
                ->color($this->projectTypePreset === 'support_monthly' ? 'primary' : 'gray')
                ->action(function (): void {
                    $this->setProjectTypePreset('support_monthly');
                }),
        ];
    }

    public function setProjectTypePreset(?string $projectTypePreset = null): void
    {
        $this->projectTypePreset = $projectTypePreset !== '' ? $projectTypePreset : null;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('project_name')->label('Проект')->wrap(),
            SelectColumn::make('project_type')
                ->label('Тип')
                ->options([
                    'support_monthly' => 'Ежемесячная',
                    'hourly_until_date' => 'Почасовка',
                    'hourly_package' => 'Почасовка пакетная',
                ])
                ->updateStateUsing(function ($state, array $record): string {
                    $this->updateProject((int) $record['project_id'], [
                        'project_type' => (string) $state,
                    ]);

                    return (string) $state;
                }),
            TextInputColumn::make('start_date')
                ->label('Старт')
                ->type('date')
                ->placeholder('—')
                ->updateStateUsing(function ($state, array $record): ?string {
                    $value = $this->normalizeDateState($state);
                    $this->updateProject((int) $record['project_id'], [
                        'start_date' => $value,
                    ]);

                    return $value;
                }),
            TextInputColumn::make('due_date')
                ->label('Завершение')
                ->type('date')
                ->placeholder('—')
                ->updateStateUsing(function ($state, array $record): ?string {
                    $value = $this->normalizeDateState($state);
                    $this->updateProject((int) $record['project_id'], [
                        'due_date' => $value,
                    ]);

                    return $value;
                }),
            TextInputColumn::make('planned_hours_total')
                ->label('План')
                ->type('number')
                ->width('5rem')
                ->extraAttributes(['style' => 'width: 5rem; min-width: 5rem; max-width: 5rem;'])
                ->extraInputAttributes(['style' => 'width: 5rem; min-width: 0;'])
                ->updateStateUsing(function ($state, array $record): ?float {
                    $value = is_numeric($state) ? (float) $state : null;
                    $this->updateProject((int) $record['project_id'], [
                        'planned_hours' => $value,
                    ]);

                    return $value;
                }),
            TextColumn::make('overrun_hours')->label('Перерасход')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')->sortable(),
            TextColumn::make('missed_profit')->label('Упущенная прибыль')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            TextColumn::make('hours_progress_pct')
                ->label('% выработки')
                ->badge()
                ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 1, ',', ' ') . '%')
                ->color(fn ($state) => $state !== null && (float) $state > 100 ? 'danger' : ((float) $state >= 90 ? 'warning' : 'success'))
                ->sortable(),
            TextColumn::make('salary_cost')->label('ФОТ')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            TextColumn::make('owner_profit')->label('Маржа')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
        ];
    }

    protected function updateProject(int $projectId, array $attributes): void
    {
        $project = Project::query()->find($projectId);

        if (! $project) {
            return;
        }

        $projectType = (string) ($attributes['project_type'] ?? $project->project_type ?? '');
        $startDate = array_key_exists('start_date', $attributes)
            ? $attributes['start_date']
            : $project->start_date?->toDateString();

        if ($projectType === 'support_monthly') {
            $attributes['due_date'] = filled($startDate)
                ? CarbonImmutable::parse((string) $startDate)->addMonthNoOverflow()->toDateString()
                : null;
        }

        $project->update($attributes);
    }

    protected function normalizeDateState(mixed $state): ?string
    {
        $state = trim((string) $state);

        if ($state === '') {
            return null;
        }

        return CarbonImmutable::parse($state)->toDateString();
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
