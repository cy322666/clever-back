<?php

namespace App\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

abstract class ArrayRecordsTableWidget extends TableWidget
{
    /**
     * @return array<int, array<string, mixed>>
     */
    abstract protected function rows(): array;

    /**
     * Normalize array-table records so Filament always gets a stable string key.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRows(array $rows): array
    {
        return array_values(array_map(function (array $row, int $index): array {
            $key = $row['__key'] ?? $row['id'] ?? $index;
            $row['__key'] = (string) $key;

            return $row;
        }, $rows, array_keys($rows)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function sortRows(array $rows): array
    {
        $sortColumn = method_exists($this, 'getTableSortColumn') ? $this->getTableSortColumn() : null;

        if (! filled($sortColumn)) {
            return $rows;
        }

        $direction = method_exists($this, 'getTableSortDirection') && $this->getTableSortDirection() === 'desc'
            ? 'desc'
            : 'asc';

        usort($rows, function (array $left, array $right) use ($sortColumn, $direction): int {
            $leftValue = data_get($left, $sortColumn);
            $rightValue = data_get($right, $sortColumn);

            if (is_numeric($leftValue) && is_numeric($rightValue)) {
                $comparison = (float) $leftValue <=> (float) $rightValue;
            } else {
                $comparison = strnatcasecmp((string) ($leftValue ?? ''), (string) ($rightValue ?? ''));
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return array_values($rows);
    }

    public function table(Table $table): Table
    {
        return parent::table(
            $table->records(fn () => collect($this->sortRows($this->normalizeRows($this->rows()))))
        );
    }

    /**
     * @param  array<int, mixed>  $columns
     * @return array<int, mixed>
     */
    protected function applyColumnOrder(string $tableKey, array $columns): array
    {
        $order = $this->storedColumnOrder($tableKey);

        if ($order === []) {
            return $columns;
        }

        $columnsByName = collect($columns)->keyBy(fn ($column): string => $this->columnName($column));
        $orderedColumns = [];

        foreach ($order as $columnName) {
            if ($columnsByName->has($columnName)) {
                $orderedColumns[] = $columnsByName->get($columnName);
            }
        }

        foreach ($columns as $column) {
            if (! in_array($this->columnName($column), $order, true)) {
                $orderedColumns[] = $column;
            }
        }

        return $orderedColumns;
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    protected function columnOrderAction(string $tableKey, array $columns): Action
    {
        return Action::make('columnOrder_'.$tableKey)
            ->label('Порядок колонок')
            ->button()
            ->outlined()
            ->modalHeading('Порядок колонок')
            ->modalDescription('Перетащи строки в нужном порядке и сохрани.')
            ->modalSubmitActionLabel('Сохранить порядок')
            ->fillForm(fn (): array => [
                'columns' => $this->columnOrderFormRows($tableKey, $columns),
            ])
            ->form([
                Repeater::make('columns')
                    ->label('Колонки')
                    ->schema([
                        Hidden::make('key'),
                        TextInput::make('label')
                            ->label('Колонка')
                            ->disabled()
                            ->dehydrated(),
                    ])
                    ->reorderable()
                    ->reorderableWithDragAndDrop()
                    ->addable(false)
                    ->deletable(false)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null),
            ])
            ->action(function (array $data) use ($tableKey, $columns): void {
                $availableColumnNames = collect($columns)
                    ->map(fn ($column): string => $this->columnName($column))
                    ->all();
                $order = collect($data['columns'] ?? [])
                    ->pluck('key')
                    ->map(fn ($columnName): string => (string) $columnName)
                    ->filter(fn (string $columnName): bool => in_array($columnName, $availableColumnNames, true))
                    ->values()
                    ->all();

                session()->put($this->columnOrderSessionKey($tableKey), $order);
            });
    }

    protected function resetColumnOrderAction(string $tableKey): Action
    {
        return Action::make('resetColumnOrder_'.$tableKey)
            ->label('Сбросить порядок')
            ->button()
            ->outlined()
            ->color('gray')
            ->action(fn () => session()->forget($this->columnOrderSessionKey($tableKey)));
    }

    /**
     * @param  array<int, mixed>  $columns
     * @return array<int, array{key: string, label: string}>
     */
    protected function columnOrderFormRows(string $tableKey, array $columns): array
    {
        $columnsByName = collect($columns)->keyBy(fn ($column): string => $this->columnName($column));

        return collect($this->applyColumnOrder($tableKey, $columns))
            ->map(fn ($column): array => [
                'key' => $this->columnName($column),
                'label' => $this->columnLabel($column),
            ])
            ->filter(fn (array $row): bool => $columnsByName->has($row['key']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function storedColumnOrder(string $tableKey): array
    {
        return collect(session()->get($this->columnOrderSessionKey($tableKey), []))
            ->map(fn ($columnName): string => (string) $columnName)
            ->filter()
            ->values()
            ->all();
    }

    protected function columnOrderSessionKey(string $tableKey): string
    {
        return 'filament.table-column-order.'.$tableKey;
    }

    protected function columnName(mixed $column): string
    {
        return method_exists($column, 'getName') ? (string) $column->getName() : spl_object_hash($column);
    }

    protected function columnLabel(mixed $column): string
    {
        if (method_exists($column, 'getLabel') && filled($label = $column->getLabel())) {
            return (string) $label;
        }

        return $this->columnName($column);
    }
}
