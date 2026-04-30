<?php

namespace App\Filament\Widgets;

use App\Models\SourceConnection;
use Filament\Tables\Columns\TextColumn;

class IntegrationConnectionsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Источники данных';

    protected int | string | array $columnSpan = 'full';

    protected function rows(): array
    {
        return SourceConnection::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SourceConnection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'driver' => $connection->driver,
                'source_key' => $connection->source_key,
                'status' => $connection->status,
                'is_enabled' => $connection->is_enabled ? 'Да' : 'Нет',
                'last_synced_at' => $connection->last_synced_at,
                'last_error_at' => $connection->last_error_at,
                'last_error_message' => $connection->last_error_message,
            ])
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Источник')->wrap(),
            TextColumn::make('driver')->label('Driver')->badge(),
            TextColumn::make('source_key')->label('Key')->badge(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('is_enabled')->label('Включён'),
            TextColumn::make('last_synced_at')->label('Синк')->dateTime('d.m.Y H:i')->placeholder('—'),
            TextColumn::make('last_error_at')->label('Ошибка')->dateTime('d.m.Y H:i')->placeholder('—'),
        ];
    }
}
