<?php

namespace App\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

abstract class AnalyticsStatsOverviewWidget extends StatsOverviewWidget
{
    /**
     * @return array<int, array{label: string, value: string, hint?: string|null, tone?: string|null, comparison?: array<string, mixed>|null}>
     */
    abstract protected function statsData(): array;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return collect($this->statsData())
            ->map(function (array $card): Stat {
                $comparison = $card['comparison'] ?? null;
                $direction = $comparison['direction'] ?? 'flat';
                $descriptionOverride = $card['description'] ?? null;

                $description = $descriptionOverride !== null
                    ? (string) $descriptionOverride
                    : trim(implode(' · ', array_filter([
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
                    ->color($card['tone'] ?? 'gray');
            })
            ->values()
            ->all();
    }
}
