<?php

namespace App\Services\Integrations\Connectors;

use App\Models\BankStatementRow;
use App\Models\CashflowEntry;
use App\Models\Client;
use App\Models\DataImportBatch;
use App\Models\ExpenseTransaction;
use App\Models\RevenueTransaction;
use App\Models\SourceConnection;
use App\Services\Integrations\Contracts\SourceConnector;
use App\Services\Integrations\SyncResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TochkaBankConnector implements SourceConnector
{
    public function supports(string $driver): bool
    {
        return in_array($driver, ['tochka', 'tochka-bank'], true);
    }

    public function sync(SourceConnection $connection): SyncResult
    {
        $settings = $this->resolveSettings($connection);

        if (! $this->isConfigured($settings)) {
            return SyncResult::fail('Точка Банк не настроен: нужен TOCHKA_TOKEN');
        }

        $from = CarbonImmutable::now()->startOfYear()->startOfDay();
        $to = CarbonImmutable::now()->endOfDay();

        try {
            $account = $this->fetchPrimaryAccount($settings);
            $accountId = $this->accountId($account);

            if ($accountId === '') {
                return SyncResult::fail('Точка Банк не вернула accountId в списке счетов');
            }

            $statement = $this->fetchStatement($settings, $from, $to, $accountId);
        } catch (Throwable $throwable) {
            report($throwable);

            return SyncResult::fail($this->tochkaErrorMessage($throwable));
        }

        if (Str::lower((string) ($statement['status'] ?? '')) !== 'ready') {
            return SyncResult::fail(
                'Выписка Точки еще не готова',
                payload: [
                    'source' => 'tochka',
                    'statement_id' => $statement['statementId'] ?? null,
                    'statement_status' => $statement['status'] ?? null,
                ],
            );
        }

        $transactions = $this->extractTransactions($statement);
        $batch = DataImportBatch::query()->create([
            'user_id' => auth()->id(),
            'source_type' => 'tochka',
            'file_name' => 'tochka-api-'.$from->toDateString().'-'.$to->toDateString(),
            'status' => 'processing',
            'row_count' => count($transactions),
            'processed_count' => 0,
            'imported_at' => now(),
            'metadata' => [
                'bank_account' => $accountId,
                'account' => $account,
                'statement_id' => $statement['statementId'] ?? null,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'only_direction' => 'credit',
            ],
        ]);

        $created = 0;
        $updated = 0;

        foreach ($transactions as $transaction) {
            $normalized = $this->normalizeTransaction($transaction, $statement, $connection->source_key);

            $statementRow = BankStatementRow::query()->updateOrCreate(
                [
                    'source_key' => $connection->source_key,
                    'external_id' => $normalized['external_id'],
                ],
                [
                    'data_import_batch_id' => $batch->id,
                    'occurred_at' => $normalized['occurred_at'],
                    'amount' => $normalized['amount'],
                    'direction' => $normalized['direction'],
                    'counterparty_name' => $normalized['counterparty_name'],
                    'purpose' => $normalized['purpose'],
                    'category' => $normalized['category'],
                    'status' => $normalized['status'],
                    'raw_payload' => $transaction,
                ],
            );

            $statementRow->wasRecentlyCreated ? $created++ : $updated++;

            $this->syncFinanceRows($statementRow, $normalized, $transaction);
            $batch->increment('processed_count');
        }

        $batch->update(['status' => 'completed']);

        return SyncResult::ok(
            pulled: count($transactions),
            created: $created,
            updated: $updated,
            payload: [
                'source' => 'tochka',
                'bank_account' => $accountId,
                'statement_id' => $statement['statementId'] ?? null,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
            ],
            message: 'Точка Банк синхронизирован',
        );
    }

    protected function fetchStatement(array $settings, CarbonImmutable $from, CarbonImmutable $to, string $accountId): array
    {
        $baseUrl = rtrim((string) $settings['base_url'], '/');

        $initResponse = $this->http($settings)->post($baseUrl.'/open-banking/v1.0/statements', [
            'Data' => [
                'Statement' => [
                    'accountId' => $accountId,
                    'startDateTime' => $from->toDateString(),
                    'endDateTime' => $to->toDateString(),
                ],
            ],
        ]);
        $initResponse->throw();

        $initStatement = data_get($initResponse->json(), 'Data.Statement', []);
        $statementId = (string) data_get($initStatement, 'statementId', '');

        if ($statementId === '') {
            return $initStatement;
        }

        $attempts = max(1, (int) ($settings['poll_attempts'] ?? 6));
        $sleepSeconds = max(0, (int) ($settings['poll_seconds'] ?? 2));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0 && $sleepSeconds > 0) {
                sleep($sleepSeconds);
            }

            $response = $this->http($settings)->get(
                $baseUrl.'/open-banking/v1.0/accounts/'.rawurlencode($accountId).'/statements/'.rawurlencode($statementId),
            );
            $response->throw();

            $statement = $this->firstStatement($response->json());

            if (Str::lower((string) ($statement['status'] ?? '')) === 'ready') {
                return $statement;
            }

            $initStatement = array_merge($initStatement, $statement);
        }

        return $initStatement;
    }

    protected function fetchPrimaryAccount(array $settings): array
    {
        $baseUrl = rtrim((string) $settings['base_url'], '/');
        $response = $this->http($settings)->get($baseUrl.'/open-banking/v1.0/accounts');
        $response->throw();

        $accounts = $this->extractAccounts($response->json() ?? []);
        $preferredAccountId = trim((string) ($settings['bank_account'] ?? ''));

        if ($preferredAccountId !== '') {
            foreach ($accounts as $account) {
                if ($this->accountId($account) === $preferredAccountId) {
                    return $account;
                }
            }
        }

        return $accounts[0] ?? [];
    }

    protected function syncFinanceRows(BankStatementRow $statementRow, array $normalized, array $transaction): void
    {
        $client = $this->matchClient((string) $normalized['counterparty_name']);
        $transactionDate = $normalized['occurred_at'];

        if ($normalized['direction'] === 'in') {
            ExpenseTransaction::query()
                ->where('source_system', 'tochka')
                ->where('source_reference', $normalized['external_id'])
                ->delete();

            $financeRow = RevenueTransaction::query()->updateOrCreate(
                [
                    'source_system' => 'tochka',
                    'source_reference' => $normalized['external_id'],
                ],
                [
                    'client_id' => $client?->id,
                    'bank_statement_row_id' => $statementRow->id,
                    'transaction_date' => $transactionDate,
                    'posted_at' => $transactionDate,
                    'amount' => $normalized['amount'],
                    'currency' => $normalized['currency'],
                    'category' => $normalized['category'] ?: 'Поступления',
                    'channel' => 'bank',
                    'status' => 'posted',
                    'note' => $normalized['purpose'],
                    'metadata' => $transaction,
                ],
            );
        } else {
            RevenueTransaction::query()
                ->where('source_system', 'tochka')
                ->where('source_reference', $normalized['external_id'])
                ->delete();

            $financeRow = ExpenseTransaction::query()->updateOrCreate(
                [
                    'source_system' => 'tochka',
                    'source_reference' => $normalized['external_id'],
                ],
                [
                    'client_id' => $client?->id,
                    'bank_statement_row_id' => $statementRow->id,
                    'transaction_date' => $transactionDate,
                    'posted_at' => $transactionDate,
                    'amount' => $normalized['amount'],
                    'currency' => $normalized['currency'],
                    'category' => $normalized['category'] ?: 'Расходы',
                    'vendor_name' => $normalized['counterparty_name'],
                    'status' => 'posted',
                    'note' => $normalized['purpose'],
                    'metadata' => $transaction,
                ],
            );
        }

        CashflowEntry::query()->updateOrCreate(
            [
                'source_type' => 'tochka',
                'source_table' => 'bank_statement_rows',
                'source_record_id' => $statementRow->id,
            ],
            [
                'entry_date' => CarbonImmutable::parse($transactionDate)->toDateString(),
                'kind' => $normalized['direction'],
                'amount' => $normalized['amount'],
                'category' => $normalized['category'] ?: ($normalized['direction'] === 'in' ? 'Поступления' : 'Расходы'),
                'description' => $normalized['purpose'],
                'client_id' => $client?->id,
                'project_id' => $financeRow->project_id,
                'payload' => $transaction,
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractTransactions(array $statement): array
    {
        $transactions = $statement['Transaction'] ?? [];

        $transactions = Arr::isAssoc($transactions) ? [$transactions] : array_values($transactions);

        return array_values(array_filter($transactions, function (array $transaction): bool {
            return Str::lower((string) ($transaction['creditDebitIndicator'] ?? 'Credit')) === 'credit';
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractAccounts(array $payload): array
    {
        $accounts = data_get($payload, 'Data.Account')
            ?? data_get($payload, 'Data.Accounts')
            ?? data_get($payload, 'accounts')
            ?? data_get($payload, 'Accounts')
            ?? data_get($payload, 'Account')
            ?? [];

        if ($accounts instanceof \Illuminate\Support\Collection) {
            $accounts = $accounts->all();
        }

        if (! is_array($accounts)) {
            return [];
        }

        if (Arr::isAssoc($accounts)) {
            return [$accounts];
        }

        return array_values(array_filter($accounts, fn ($account): bool => is_array($account)));
    }

    protected function accountId(array $account): string
    {
        return trim((string) (
            data_get($account, 'accountId')
            ?? data_get($account, 'AccountId')
            ?? data_get($account, 'account_id')
            ?? data_get($account, 'id')
            ?? data_get($account, 'account')
            ?? ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeTransaction(array $transaction, array $statement, string $sourceKey): array
    {
        $indicator = Str::lower((string) ($transaction['creditDebitIndicator'] ?? 'Credit'));
        $direction = $indicator === 'debit' ? 'out' : 'in';
        $counterparty = $direction === 'in'
            ? data_get($transaction, 'DebtorParty.name')
            : data_get($transaction, 'CreditorParty.name');
        $amount = $this->toFloat(data_get($transaction, 'Amount.amountNat', data_get($transaction, 'Amount.amount', 0)));
        $occurredAt = $transaction['documentProcessDate']
            ?? $transaction['date']
            ?? $statement['endDateTime']
            ?? now()->toDateString();

        return [
            'external_id' => $this->transactionExternalId($transaction, $sourceKey),
            'occurred_at' => CarbonImmutable::parse($occurredAt)->toDateTimeString(),
            'amount' => abs($amount),
            'currency' => (string) data_get($transaction, 'Amount.currency', 'RUB'),
            'direction' => $direction,
            'counterparty_name' => trim((string) $counterparty) ?: null,
            'purpose' => trim((string) ($transaction['description'] ?? '')) ?: null,
            'category' => trim((string) ($transaction['transactionTypeCode'] ?? '')) ?: null,
            'status' => Str::lower((string) ($transaction['status'] ?? 'booked')),
        ];
    }

    protected function transactionExternalId(array $transaction, string $sourceKey): string
    {
        $externalId = $transaction['transactionId'] ?? $transaction['paymentId'] ?? null;

        if ($externalId) {
            return (string) $externalId;
        }

        return $sourceKey.':'.sha1(json_encode($transaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    protected function firstStatement(array $payload): array
    {
        $statement = data_get($payload, 'Data.Statement', []);

        if (Arr::isAssoc($statement)) {
            return $statement;
        }

        return (array) ($statement[0] ?? []);
    }

    protected function http(array $settings): PendingRequest
    {
        return Http::withToken((string) $settings['token'])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($settings['connect_timeout'] ?? 15))
            ->timeout((int) ($settings['timeout'] ?? 60))
            ->retry(3, 1000);
    }

    protected function tochkaErrorMessage(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        if (str_contains($message, 'status code 403')) {
            return 'Точка Банк вернула 403: токен виден, но нет доступа к счетам/выпискам. Проверь права Open Banking: ReadAccountsBasic, ReadAccountsDetail и ReadStatements.';
        }

        if (str_contains($message, 'cURL error')) {
            return 'Точка Банк временно недоступна по сети: '.$message;
        }

        return $message;
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

    protected function resolveSettings(SourceConnection $connection): array
    {
        return array_merge(
            config('services.tochka', []),
            config("services.{$connection->source_key}", []),
            $this->filledSettings(is_array($connection->settings ?? null) ? $connection->settings : []),
        );
    }

    protected function isConfigured(array $settings): bool
    {
        return filled($settings['base_url'] ?? null)
            && filled($settings['token'] ?? null);
    }

    protected function filledSettings(array $settings): array
    {
        return array_filter($settings, fn ($value): bool => filled($value));
    }

    protected function toFloat(mixed $value): float
    {
        return (float) str_replace([' ', ','], ['', '.'], (string) $value);
    }
}
