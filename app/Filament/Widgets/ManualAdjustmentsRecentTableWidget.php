<?php

namespace App\Filament\Widgets;

use App\Models\ManualAdjustment;
use Filament\Tables\Columns\TextColumn;

class ManualAdjustmentsRecentTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Последние корректировки';

    protected int | string | array $columnSpan = 'full';

    protected function rows(): array
    {
        return ManualAdjustment::query()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ManualAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'entity_type' => $adjustment->entity_type,
                'entity_id' => $adjustment->entity_id,
                'adjustment_type' => $adjustment->adjustment_type,
                'adjustment_date' => $adjustment->adjustment_date,
                'note' => $adjustment->note,
                'metadata' => $adjustment->metadata,
            ])
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('entity_type')->label('Сущность')->badge(),
            TextColumn::make('adjustment_type')->label('Тип')->badge(),
            TextColumn::make('adjustment_date')->label('Дата')->date('d.m.Y'),
            TextColumn::make('note')->label('Комментарий')->wrap()->placeholder('—'),
        ];
    }
}
