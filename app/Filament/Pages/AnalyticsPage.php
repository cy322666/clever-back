<?php

namespace App\Filament\Pages;

use App\Support\AnalyticsPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
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
            $this->periodAction('today', 'Сегодня', $currentPeriod->key),
            $this->periodAction('7d', 'Неделя', $currentPeriod->key),
            $this->periodAction('month', 'Текущий месяц', $currentPeriod->key),
            $this->periodAction('prev-month', 'Прошлый месяц', $currentPeriod->key),
            $this->periodAction('30d', 'Последние 30 дней', $currentPeriod->key),
            $this->periodAction('quarter', 'Квартал', $currentPeriod->key),
            Action::make('period_custom')
                ->label('Произвольный период')
                ->button()
                ->outlined($currentPeriod->key !== 'custom')
                ->color($currentPeriod->key === 'custom' ? 'primary' : 'gray')
                ->form([
                    DatePicker::make('from')
                        ->label('С')
                        ->default($currentPeriod->from->toDateString())
                        ->required(),
                    DatePicker::make('to')
                        ->label('По')
                        ->default($currentPeriod->to->toDateString())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    redirect()->to(static::getUrl([
                        'period' => 'custom',
                        'from' => $data['from'],
                        'to' => $data['to'],
                    ]));
                }),
        ];
    }

    protected function periodAction(string $period, string $label, string $currentPeriod): Action
    {
        $isActive = $period === $currentPeriod;

        return Action::make('period_'.str_replace('-', '_', $period))
            ->label($label)
            ->button()
            ->outlined(! $isActive)
            ->color($isActive ? 'primary' : 'gray')
            ->url($this->periodUrl($period));
    }

    protected function periodUrl(string $period): string
    {
        $query = request()->query();
        unset($query['from'], $query['to']);

        if ($period === 'month') {
            unset($query['period']);
        } else {
            $query['period'] = $period;
        }

        return static::getUrl($query);
    }

    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }
}
