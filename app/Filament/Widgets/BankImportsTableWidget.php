<?php

namespace App\Filament\Widgets;

use App\Models\DataImportBatch;
use Filament\Tables\Columns\TextColumn;

class BankImportsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Импорты выписки';

    protected int | string | array $columnSpan = 'full';

    protected function rows(): array
    {
        return DataImportBatch::query()
            ->where('source_type', 'bank')
            ->latest('imported_at')
            ->limit(20)
            ->get()
            ->map(fn (DataImportBatch $batch) => [
                'id' => $batch->id,
                'file_name' => $batch->file_name,
                'status' => $batch->status,
                'row_count' => $batch->row_count,
                'processed_count' => $batch->processed_count,
                'imported_at' => $batch->imported_at,
            ])
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('file_name')->label('Файл')->wrap(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('row_count')->label('Строк'),
            TextColumn::make('processed_count')->label('Обработано'),
            TextColumn::make('imported_at')->label('Импорт')->dateTime('d.m.Y H:i'),
        ];
    }
}
