<?php

namespace App\Filament\Widgets;

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
}
