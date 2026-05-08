<?php

namespace App\Services\Invoices;

use App\Models\BankStatementRow;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoicePaymentMatch;
use App\Models\RevenueTransaction;
use App\Models\SourceConnection;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class TochkaInvoicePaymentMatcher
{
    public function __construct(
        protected AmoCrmConnector $amoCrmConnector,
    ) {}

    /**
     * @return array{processed:int, applied:int, skipped:int, ambiguous:int, failed:int, dry_run:bool}
     */
    public function match(?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $dryRun = false, bool $force = false, string $paidStatus = 'Оплачен'): array
    {
        $stats = [
            'processed' => 0,
            'applied' => 0,
            'skipped' => 0,
            'ambiguous' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
        ];

        $query = BankStatementRow::query()
            ->where('source_key', 'tochka')
            ->where('direction', 'in')
            ->where('amount', '>', 0);

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        $query->chunkById(200, function (EloquentCollection $rows) use (&$stats, $dryRun, $force, $paidStatus): void {
            foreach ($rows as $row) {
                $stats['processed']++;
                $result = $this->matchRow($row, $dryRun, $force, $paidStatus);
                $stats[$result]++;
            }
        });

        return $stats;
    }

    protected function matchRow(BankStatementRow $row, bool $dryRun, bool $force, string $paidStatus): string
    {
        $existing = InvoicePaymentMatch::query()
            ->where('bank_statement_row_id', $row->id)
            ->first();

        if (! $force && $existing?->status === 'applied') {
            return 'skipped';
        }

        $inn = $this->counterpartyInn($row);
        $amount = (float) $row->amount;
        $revenueTransaction = RevenueTransaction::query()
            ->where('bank_statement_row_id', $row->id)
            ->first();

        if ($inn === null) {
            $this->record($row, $revenueTransaction, null, null, 'skipped', 'no_inn', $dryRun);

            return 'skipped';
        }

        $client = Client::query()->where('inn', $inn)->first();

        if (! $client) {
            $this->record($row, $revenueTransaction, null, null, 'skipped', 'no_client_by_inn', $dryRun, [
                'inn' => $inn,
            ]);

            return 'skipped';
        }

        $candidates = $this->candidateInvoices($client, $amount);

        if ($candidates->isEmpty()) {
            $this->record($row, $revenueTransaction, $client, null, 'skipped', 'no_unpaid_invoice_for_inn_and_amount', $dryRun, [
                'inn' => $inn,
                'amount' => $amount,
            ]);

            return 'skipped';
        }

        if ($candidates->count() > 1) {
            $this->record($row, $revenueTransaction, $client, null, 'ambiguous', 'multiple_unpaid_invoices_for_inn_and_amount', $dryRun, [
                'inn' => $inn,
                'amount' => $amount,
                'invoice_ids' => $candidates->pluck('id')->values()->all(),
                'invoice_external_ids' => $candidates->pluck('external_id')->values()->all(),
            ]);

            return 'ambiguous';
        }

        /** @var Invoice $invoice */
        $invoice = $candidates->first();

        if ($dryRun) {
            return 'applied';
        }

        try {
            $sourceConnection = SourceConnection::query()->find($invoice->source_connection_id)
                ?? SourceConnection::query()->where('source_key', 'amo')->first();

            if (! $sourceConnection) {
                throw new \RuntimeException('amo source connection not found');
            }

            $this->amoCrmConnector->updateInvoicePaymentStatus($sourceConnection, $invoice, $paidStatus);

            $invoice->update(['payment_status' => $paidStatus]);

            if ($revenueTransaction) {
                $revenueTransaction->update(['invoice_id' => $invoice->id]);
            }

            $this->record($row, $revenueTransaction, $client, $invoice, 'applied', null, false, [
                'inn' => $inn,
                'paid_status' => $paidStatus,
            ]);

            return 'applied';
        } catch (Throwable $throwable) {
            report($throwable);

            $this->record($row, $revenueTransaction, $client, $invoice, 'failed', $throwable->getMessage(), false, [
                'inn' => $inn,
            ]);

            return 'failed';
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    protected function candidateInvoices(Client $client, float $amount): Collection
    {
        $tolerance = (float) config('services.amo.invoice_payment_match_tolerance', 1);
        $customerIds = Buyer::query()
            ->where('client_id', $client->id)
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Invoice::query()
            ->where('source_key', 'amo')
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->orderBy('invoice_date')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $this->invoiceIsUnpaid($invoice))
            ->filter(fn (Invoice $invoice): bool => $this->invoiceBelongsToClient($invoice, $client, $customerIds))
            ->values();
    }

    /**
     * @param  array<int, string>  $customerIds
     */
    protected function invoiceBelongsToClient(Invoice $invoice, Client $client, array $customerIds): bool
    {
        if ($customerIds !== [] && in_array((string) $invoice->customer_external_id, $customerIds, true)) {
            return true;
        }

        $clientCompanyId = trim((string) $client->external_id);
        $clientInn = $this->normalizeInn($client->inn);

        foreach ($this->invoiceCompanies($invoice) as $company) {
            $companyId = trim((string) data_get($company, 'id', ''));

            if ($clientCompanyId !== '' && $companyId === $clientCompanyId) {
                return true;
            }

            if ($clientInn !== null && $this->companyInn($company) === $clientInn) {
                return true;
            }
        }

        return false;
    }

    protected function invoiceIsUnpaid(Invoice $invoice): bool
    {
        $status = Str::of((string) $invoice->payment_status)->lower()->trim()->toString();

        if ($status === '') {
            return true;
        }

        if (Str::contains($status, ['отмен', 'cancel'])) {
            return false;
        }

        return ! in_array($status, ['оплачен', 'paid'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function invoiceCompanies(Invoice $invoice): array
    {
        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $companies = data_get($metadata, 'amo_customer._embedded.companies', data_get($metadata, 'amo_customer.companies', []));

        if (! is_array($companies)) {
            return [];
        }

        return collect($companies)
            ->filter(fn (mixed $company): bool => is_array($company))
            ->values()
            ->all();
    }

    protected function companyInn(array $company): ?string
    {
        foreach (($company['custom_fields_values'] ?? []) as $field) {
            $name = Str::lower((string) ($field['field_name'] ?? $field['name'] ?? ''));
            $code = Str::lower((string) ($field['field_code'] ?? $field['code'] ?? ''));

            if ($name !== 'инн' && ! Str::contains($name, 'инн') && $code !== 'inn') {
                continue;
            }

            foreach (($field['values'] ?? []) as $value) {
                $inn = $this->normalizeInn($value['value'] ?? null);

                if ($inn !== null) {
                    return $inn;
                }
            }
        }

        return null;
    }

    protected function counterpartyInn(BankStatementRow $row): ?string
    {
        $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
        $party = data_get($payload, 'DebtorParty', []);

        return $this->normalizeInn($this->findValueByKeys(is_array($party) ? $party : [], [
            'inn',
            'инн',
            'innkio',
            'taxid',
            'tax_id',
            'taxnumber',
            'tax_number',
            'taxidentificationnumber',
            'tax_identification_number',
            'payerinn',
            'payer_inn',
            'recipientinn',
            'recipient_inn',
            'counterpartyinn',
            'counterparty_inn',
            'debtorinn',
            'debtor_inn',
            'creditorinn',
            'creditor_inn',
        ]));
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function findValueByKeys(mixed $payload, array $keys): mixed
    {
        if (! is_array($payload)) {
            return null;
        }

        $normalizedKeys = collect($keys)
            ->map(fn (string $key): string => $this->normalizeKey($key))
            ->all();

        foreach ($payload as $key => $value) {
            if (in_array($this->normalizeKey((string) $key), $normalizedKeys, true)) {
                return $value;
            }

            if (is_array($value)) {
                $nested = $this->findValueByKeys($value, $keys);

                if ($nested !== null && $nested !== '') {
                    return $nested;
                }
            }
        }

        return null;
    }

    protected function normalizeKey(string $key): string
    {
        return Str::of($key)
            ->lower()
            ->replace(['_', '-', ' '], '')
            ->toString();
    }

    protected function normalizeInn(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return in_array(strlen($digits), [10, 12], true) ? $digits : null;
    }

    protected function record(
        BankStatementRow $row,
        ?RevenueTransaction $revenueTransaction,
        ?Client $client,
        ?Invoice $invoice,
        string $status,
        ?string $reason,
        bool $dryRun,
        array $metadata = [],
    ): ?InvoicePaymentMatch {
        if ($dryRun) {
            return null;
        }

        return InvoicePaymentMatch::query()->updateOrCreate(
            ['bank_statement_row_id' => $row->id],
            [
                'revenue_transaction_id' => $revenueTransaction?->id,
                'invoice_id' => $invoice?->id,
                'client_id' => $client?->id,
                'source_key' => 'tochka',
                'status' => $status,
                'inn' => $metadata['inn'] ?? $this->counterpartyInn($row),
                'counterparty_name' => $row->counterparty_name,
                'payment_amount' => (float) $row->amount,
                'invoice_amount' => $invoice?->amount,
                'reason' => $reason,
                'applied_at' => $status === 'applied' ? now() : null,
                'metadata' => array_merge([
                    'bank_statement_row_id' => $row->id,
                    'revenue_transaction_id' => $revenueTransaction?->id,
                    'invoice_id' => $invoice?->id,
                    'invoice_external_id' => $invoice?->external_id,
                    'payment_date' => $row->occurred_at?->toDateTimeString(),
                    'purpose' => $row->purpose,
                ], $metadata),
            ],
        );
    }
}
