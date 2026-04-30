<?php

namespace App\Filament\Widgets;

use App\Models\Buyer;
use App\Models\SourceConnection;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

class BuyersTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Список покупателей';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        $amoBaseUrl = $this->amoBaseUrl();

        return Buyer::query()
            ->with('client')
            ->orderByRaw('coalesce(next_date, created_at) desc nulls last')
            ->orderByDesc('id')
            ->get()
            ->map(function (Buyer $buyer) use ($amoBaseUrl): array {
                $externalId = trim((string) $buyer->external_id);
                $name = trim((string) $buyer->name);

                return [
                    'id' => $buyer->id,
                    '__key' => (string) $buyer->id,
                    'name' => $name !== '' ? $name : 'Покупатель #'.$externalId,
                    'company' => $buyer->client?->name ?? '—',
                    'status' => $buyer->status ?: '—',
                    'periodicity' => $this->periodicityLabel($buyer->periodicity),
                    'purchases_count' => (float) ($buyer->purchases_count ?? 0),
                    'average_check' => (float) ($buyer->average_check ?? 0),
                    'ltv' => (float) ($buyer->ltv ?? 0),
                    'next_price' => (float) ($buyer->next_price ?? 0),
                    'next_date' => $buyer->next_date,
                    'amo_url' => $amoBaseUrl !== null && $externalId !== ''
                        ? $amoBaseUrl.'/customers/detail/'.$externalId
                        : null,
                ];
            })
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Покупатель')->wrap()->searchable(),
            TextColumn::make('company')->label('Компания')->wrap()->searchable(),
            TextColumn::make('status')->label('Статус')->badge()->sortable(),
            TextColumn::make('periodicity')->label('Периодичность')->badge(),
            TextColumn::make('purchases_count')->label('Покупок')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' '))->sortable(),
            TextColumn::make('average_check')->label('Средний чек')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            TextColumn::make('ltv')->label('LTV')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            TextColumn::make('next_price')->label('Следующая сумма')->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state, 0, ',', ' ') . ' ₽' : '—')->sortable(),
            TextColumn::make('next_date')->label('Следующая дата')->dateTime('d.m.Y')->placeholder('—')->sortable(),
            IconColumn::make('amo_url')
                ->label('')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (?string $state) => filled($state) ? 'primary' : 'gray')
                ->tooltip('Открыть покупателя в amo')
                ->url(fn (?string $state) => $state)
                ->openUrlInNewTab(),
        ];
    }

    private function amoBaseUrl(): ?string
    {
        $fromConnection = SourceConnection::query()
            ->whereIn('source_key', ['amo', 'amocrm'])
            ->get()
            ->map(fn (SourceConnection $connection): string => trim((string) data_get($connection->settings, 'base_url', '')))
            ->filter()
            ->first();

        $baseUrl = $fromConnection ?: trim((string) config('services.amo.base_url', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : null;
    }

    private function periodicityLabel(mixed $periodicity): string
    {
        return match ((string) $periodicity) {
            '0' => 'Разовая',
            '1' => 'День',
            '7' => 'Неделя',
            '30' => 'Месяц',
            '90' => 'Квартал',
            '365' => 'Год',
            default => filled($periodicity) ? (string) $periodicity : '—',
        };
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('month');
    }
}
