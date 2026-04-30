<?php

namespace App\Services\Imports;

use App\Models\BankStatementRow;
use App\Models\Client;
use App\Models\CashflowEntry;
use App\Models\DataImportBatch;
use App\Models\ExpenseTransaction;
use App\Models\RevenueTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class BankStatementImportService
{
    public function import(UploadedFile $file, array $mapping = []): DataImportBatch
    {
        $rows = $this->loadRows($file);
        $mapping = array_merge([
            'date' => 'date',
            'amount' => 'amount',
            'direction' => 'direction',
            'counterparty' => 'counterparty',
            'purpose' => 'purpose',
            'category' => 'category',
        ], $mapping);

        $batch = DataImportBatch::query()->create([
            'user_id' => auth()->id(),
            'source_type' => 'bank',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $file->store('imports/banks'),
            'status' => 'processing',
            'row_count' => count($rows),
            'processed_count' => 0,
            'imported_at' => now(),
        ]);

        foreach ($rows as $index => $row) {
            $direction = strtolower((string) ($row[$mapping['direction']] ?? 'in'));
            $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($row[$mapping['amount']] ?? 0));
            $occurredAt = $this->parseDate($row[$mapping['date']] ?? null);
            $counterparty = trim((string) ($row[$mapping['counterparty']] ?? ''));
            $purpose = trim((string) ($row[$mapping['purpose']] ?? ''));
            $category = trim((string) ($row[$mapping['category']] ?? ''));

            $statementRow = BankStatementRow::query()->create([
                'data_import_batch_id' => $batch->id,
                'occurred_at' => $occurredAt,
                'amount' => abs($amount),
                'direction' => str_contains($direction, 'out') || str_contains($direction, 'рас') ? 'out' : 'in',
                'counterparty_name' => $counterparty,
                'purpose' => $purpose,
                'category' => $category ?: null,
                'status' => 'imported',
                'raw_payload' => $row,
            ]);

            $client = $this->matchClient($counterparty);

            if ($statementRow->direction === 'in') {
                $revenue = RevenueTransaction::query()->create([
                    'client_id' => $client?->id,
                    'bank_statement_row_id' => $statementRow->id,
                    'source_system' => 'bank-import',
                    'source_reference' => $file->getClientOriginalName().':'.($index + 1),
                    'transaction_date' => $occurredAt,
                    'posted_at' => $occurredAt,
                    'amount' => abs($amount),
                    'currency' => 'RUB',
                    'category' => $category ?: 'Поступления',
                    'channel' => 'bank',
                    'status' => 'posted',
                    'note' => $purpose,
                ]);

                CashflowEntry::query()->create([
                    'source_type' => RevenueTransaction::class,
                    'source_table' => 'revenue_transactions',
                    'source_record_id' => $revenue->id,
                    'entry_date' => CarbonImmutable::parse($occurredAt)->toDateString(),
                    'kind' => 'in',
                    'amount' => abs($amount),
                    'category' => $category ?: 'Поступления',
                    'description' => $purpose,
                    'client_id' => $client?->id,
                    'payload' => $row,
                ]);
            } else {
                $expense = ExpenseTransaction::query()->create([
                    'client_id' => $client?->id,
                    'bank_statement_row_id' => $statementRow->id,
                    'source_system' => 'bank-import',
                    'source_reference' => $file->getClientOriginalName().':'.($index + 1),
                    'transaction_date' => $occurredAt,
                    'posted_at' => $occurredAt,
                    'amount' => abs($amount),
                    'currency' => 'RUB',
                    'category' => $category ?: 'Расходы',
                    'vendor_name' => $counterparty,
                    'status' => 'posted',
                    'note' => $purpose,
                ]);

                CashflowEntry::query()->create([
                    'source_type' => ExpenseTransaction::class,
                    'source_table' => 'expense_transactions',
                    'source_record_id' => $expense->id,
                    'entry_date' => CarbonImmutable::parse($occurredAt)->toDateString(),
                    'kind' => 'out',
                    'amount' => abs($amount),
                    'category' => $category ?: 'Расходы',
                    'description' => $purpose,
                    'client_id' => $client?->id,
                    'payload' => $row,
                ]);
            }

            $batch->increment('processed_count');
        }

        $batch->update([
            'status' => 'completed',
        ]);

        return $batch->refresh();
    }

    protected function loadRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            $handle = fopen($file->getRealPath(), 'r');
            $headers = array_map(fn ($header) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header))), fgetcsv($handle) ?: []);
            $rows = [];

            while (($values = fgetcsv($handle)) !== false) {
                $rows[] = array_combine($headers, $values) ?: [];
            }

            fclose($handle);

            return $rows;
        }

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = [];
            $headers = [];

            foreach ($sheet->toArray(null, true, true, true) as $index => $values) {
                if ($index === 1) {
                    $headers = array_map(fn ($value) => strtolower(trim((string) $value)), array_values($values));
                    continue;
                }

                $rows[] = array_combine($headers, array_values($values)) ?: [];
            }

            return $rows;
        }

        throw new RuntimeException('Unsupported bank statement file format.');
    }

    protected function parseDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateTimeString();
    }

    protected function matchClient(string $counterparty): ?Client
    {
        if ($counterparty === '') {
            return null;
        }

        return Client::query()
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($counterparty).'%'])
            ->first();
    }
}
