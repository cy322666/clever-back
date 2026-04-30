<?php

namespace App\Filament\Pages;

use App\Support\AnalyticsPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Widgets\WidgetConfiguration;

abstract class AnalyticsPage extends Page
{
    /**
     * @return array<class-string | WidgetConfiguration>
     */
    abstract protected function widgets(): array;

    protected function periodState(): array
    {
        return AnalyticsPeriod::fromRequest(request())->toArray();
    }

    /**
     * @param  class-string  $widget
     */
    protected function widget(string $widget, array $properties = [], bool $withPeriod = false): WidgetConfiguration
    {
        return $widget::make(
            $withPeriod
                ? ['period' => $this->periodState(), ...$properties]
                : $properties,
        );
    }

    /**
     * @return array<class-string | WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        return $this->widgets();
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getHeaderWidgetsColumns(): int | array
    {
        return 2;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $currentPeriod = AnalyticsPeriod::fromRequest(request());

        return [
            Action::make('period')
                ->label('Период')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->modalHeading('Фильтр периода')
                ->form([
                    Select::make('period')
                        ->label('Период')
                        ->required()
                        ->default($currentPeriod->key)
                        ->options([
                            'today' => 'Сегодня',
                            '7d' => '7 дней',
                            '30d' => '30 дней',
                            'month' => 'Текущий месяц',
                            'prev-month' => 'Прошлый месяц',
                            'quarter' => 'Квартал',
                            'all' => 'Всё время',
                            'custom' => 'Свой диапазон',
                        ]),
                    DatePicker::make('from')
                        ->label('С')
                        ->default($currentPeriod->from->toDateString())
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom'),
                    DatePicker::make('to')
                        ->label('По')
                        ->default($currentPeriod->to->toDateString())
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom'),
                ])
                ->action(function (array $data): void {
                    $query = request()->query();
                    $query['period'] = $data['period'] ?? '30d';

                    if (($query['period'] ?? null) === 'custom') {
                        $query['from'] = $data['from'] ?? null;
                        $query['to'] = $data['to'] ?? null;
                    } else {
                        unset($query['from'], $query['to']);
                    }

                    $this->redirect(static::getUrl($query), navigate: true);
                }),
        ];
    }

    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }
}
