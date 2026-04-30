<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerDashboardService;
use App\Support\AnalyticsPeriod;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OwnerStatsOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Обзор собственника';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $period = $this->resolvePeriod();
        $data = app(OwnerDashboardService::class)->build($period);

        return collect($data['overview_cards'] ?? [])
            ->map(function (array $card): Stat {
                $comparison = $card['comparison'] ?? null;
                $direction = $comparison['direction'] ?? 'flat';

                $description = trim(implode(' · ', array_filter([
                    $card['hint'] ?? null,
                    isset($comparison['delta_percent']) && $comparison['delta_percent'] !== null
                        ? ($comparison['delta'] >= 0 ? '+' : '') . number_format((float) $comparison['delta_percent'], 1, ',', ' ') . '% к пред. периоду'
                        : null,
                ])));

                $descriptionColor = match ($direction) {
                    'up' => 'success',
                    'down' => 'danger',
                    default => 'gray',
                };

                $descriptionIcon = match ($direction) {
                    'up' => Heroicon::OutlinedArrowTrendingUp,
                    'down' => Heroicon::OutlinedArrowTrendingDown,
                    default => Heroicon::OutlinedMinus,
                };

                return Stat::make($card['label'] ?? 'Показатель', $card['value'] ?? '0')
                    ->description($description)
                    ->descriptionColor($descriptionColor)
                    ->descriptionIcon($descriptionIcon)
                    ->color($card['tone'] ?? 'gray')
                    ->chartColor($card['tone'] ?? 'gray');
            })
            ->values()
            ->all();
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
