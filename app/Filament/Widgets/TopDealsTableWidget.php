<?php

namespace App\Filament\Widgets;

use App\Models\SalesOpportunity;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class TopDealsTableWidget extends BaseWidget
{
    protected static ?string $heading = 'Крупные сделки';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function getTableQuery(): Builder
    {
        $period = $this->resolvePeriod();
        $primaryPipelineName = trim((string) config('dashboard.primary_sales_pipeline_name', 'Основная'));
        $allowedPipelineNames = collect(config('dashboard.amo_allowed_pipeline_names', []))
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
        $excludedPipelineNames = collect(config('dashboard.amo_excluded_pipeline_names', []))
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        $query = SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($primaryPipelineName)])
            ->whereBetween(
                \DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'),
                [$period->from, $period->to]
            )
            ->select('sales_opportunities.*')
            ->with(['client', 'owner'])
            ->orderByDesc('sales_opportunities.amount');

        if (! empty($allowedPipelineNames)) {
            $query->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')',
                $allowedPipelineNames
            );
        } elseif (! empty($excludedPipelineNames)) {
            $query->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')',
                $excludedPipelineNames
            );
        }

        return $query;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Сделка')->searchable()->wrap(),
            TextColumn::make('client.name')->label('Клиент')->placeholder('—')->searchable(),
            TextColumn::make('amount')
                ->label('Сумма')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
            TextColumn::make('status')->label('Статус')->badge()->sortable(),
            TextColumn::make('owner.name')->label('Ответственный')->placeholder('—'),
            TextColumn::make('last_activity_at')->label('Активность')->dateTime('d.m.Y H:i')->placeholder('—'),
        ];
    }

    protected function getTableHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->exports([
                    ExcelExport::make('top_deals')
                        ->fromTable()
                        ->askForFilename(),
                ]),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
