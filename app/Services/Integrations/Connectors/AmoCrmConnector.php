<?php

namespace App\Services\Integrations\Connectors;

use App\Models\Buyer;
use App\Models\Client;
use App\Models\EntityProduct;
use App\Models\Invoice;
use App\Models\Pipeline;
use App\Models\RevenueTransaction;
use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Models\SourceConnection;
use App\Models\Stage;
use App\Services\Integrations\Contracts\SourceConnector;
use App\Services\Integrations\SyncResult;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Ufee\AmoV4\ApiClient;

/**
 * Коннектор amoCRM для синхронизации сделок, клиентов и услуг.
 */
class AmoCrmConnector
{
    protected array $catalogElementCache = [];
    protected array $catalogListCache = [];
    protected ?int $companyInnFieldId = null;
    protected ?int $companyLtvFieldId = null;
    protected ?int $companySalesAmountFieldId = null;
    protected ?int $companyAverageCheckFieldId = null;
    protected ?int $companySalesCountFieldId = null;
    protected array $pipelineCache = [];
    protected array $stageCache = [];

    public function supports(string $driver): bool
    {
        return in_array($driver, ['amo', 'amocrm'], true);
    }

    public function getProduct(string|int $productId, ?array $settings = null): ?array
    {
        $cacheKey = (string) $productId;

        if (array_key_exists($cacheKey, $this->catalogElementCache)) {
            return $this->catalogElementCache[$cacheKey];
        }

        $products = $this->getProducts([$productId], $settings ?? config('services.amo', []));

        return $this->catalogElementCache[$cacheKey] = ($products[$cacheKey] ?? null);
    }

    /**
     * @throws \Exception
     */
    public function sync(SourceConnection $connection)
    {
        $settings = $this->resolveSettings($connection);

        if (! $this->isConfigured($settings)) {
            return SyncResult::fail('amoCRM не настроен');
        }

        $pipelineIds = Pipeline::query()
            ->pluck('external_id')
            ->filter()
            ->map(fn ($value): string => (string) $value)
            ->values()
            ->all();
        $warnings = [];
        $pulled = 0;
        $created = 0;
        $updated = 0;

        foreach ($pipelineIds as $pipelineId) {
            try {
            $leads = $this->fetchAmoCollection($settings, '/api/v4/leads', [
                'filter' => [
                    'pipeline_id' => $pipelineId,
                    'created_at' => [
                        'from' => CarbonImmutable::now()->startOfYear()->startOfDay()->timestamp,
                        'to' => CarbonImmutable::now()->endOfDay()->timestamp,
                    ],
                ],
                'with' => 'catalog_elements,companies',
            ], 'leads');

            foreach ($leads as $lead) {
                $client = null;
                $company = data_get($lead, '_embedded.companies.0');
                $pipeline = $this->pipelineForAmoLead($lead);
                $stage = $this->stageForAmoLead($lead, $pipeline);
                $status = $this->statusForStage($stage, data_get($lead, 'status_id'));
                $createdAt = $this->toDateTime(data_get($lead, 'created_at'));
                $closedAt = $this->toDateTime(data_get($lead, 'closed_at'));
                $updatedAt = $this->toDateTime(data_get($lead, 'updated_at'));

                if ($company !== null) {
                    $client = $this->upsertAmoCompanyClient($company, data_get($lead, 'name'));
                }

                $leadModel = SalesLead::query()->updateOrCreate(
                    [
                        'external_id' => data_get($lead, 'id'),
                    ],
                    [
                        'client_id' => $client?->id,
                        'name' => data_get($lead, 'name'),
                        'source_channel' => $this->leadSourceChannel($lead),
                        'status_id' => data_get($lead, 'status_id'),
                        'budget_amount' => data_get($lead, 'price', 0),
                        'lead_created_at' => $createdAt?->toDateTimeString(),
                        'lead_closed_at' => $closedAt?->toDateTimeString(),
                        'last_activity_at' => $updatedAt?->toDateTimeString(),
                        'pipeline_id' => $pipeline?->id,
                        'metadata' => [
                            'amo_lead' => $lead,
                        ],
                    ]
                );
                $pulled++;
                $leadModel->wasRecentlyCreated ? $created++ : $updated++;

                $opportunityModel = SalesOpportunity::query()->updateOrCreate(
                    [
                        'source_system' => 'amoCRM',
                        'external_id' => (string) data_get($lead, 'id'),
                    ],
                    [
                        'client_id' => $client?->id,
                        'pipeline_id' => $pipeline?->id,
                        'stage_id' => $stage?->id,
                        'name' => data_get($lead, 'name') ?: 'amoCRM lead '.data_get($lead, 'id'),
                        'amount' => (float) data_get($lead, 'price', 0),
                        'probability' => $status === 'won' ? 100 : (float) ($stage?->probability ?? 0),
                        'status' => $status,
                        'opened_at' => $createdAt?->toDateTimeString(),
                        'won_at' => $status === 'won' ? $closedAt?->toDateTimeString() : null,
                        'lost_at' => $status === 'lost' ? $closedAt?->toDateTimeString() : null,
                        'closed_at' => in_array($status, ['won', 'lost'], true) ? $closedAt?->toDateTimeString() : null,
                        'last_activity_at' => $updatedAt?->toDateTimeString(),
                        'source_channel' => $this->leadSourceChannel($lead),
                        'metadata' => [
                            'amo_lead' => $lead,
                        ],
                    ]
                );
                $pulled++;
                $opportunityModel->wasRecentlyCreated ? $created++ : $updated++;

                $catalogElement = data_get($lead, '_embedded.catalog_elements.0');

                if (is_array($catalogElement)) {
                    $productModel = $this->upsertLinkedProduct(
                        $connection,
                        'lead',
                        $lead,
                        $catalogElement,
                    );
                    $pulled++;
                    $productModel->wasRecentlyCreated ? $created++ : $updated++;
                }
            }
            } catch (Throwable $throwable) {
                report($throwable);
                $warnings[] = 'Лиды воронки '.$pipelineId.': '.$throwable->getMessage();
            }
        }

        try {
            $customers = $this->fetchAmoCollection($settings, '/api/v4/customers', [
                'with' => 'catalog_elements,companies',
            ], 'customers');
        } catch (Throwable $throwable) {
            report($throwable);
            $warnings[] = 'Клиенты: '.$throwable->getMessage();
            $customers = [];
        }

        foreach ($customers as $customer) {
            $client = null;
            $company = data_get($customer, '_embedded.companies.0');

            if ($company !== null) {
                $client = $this->upsertAmoCompanyClient($company, data_get($customer, 'name'));
            }

            $buyerModel = Buyer::query()->updateOrCreate([
                'external_id' => data_get($customer, 'id'),
            ], [
                'client_id' => $client?->id,
                'name' => data_get($customer, 'name'),
                'status' => data_get($customer, 'status_id'),
                'periodicity' => data_get($customer, 'periodicity'),
                'purchases_count' => (float) (data_get($customer, 'purchases_count') ?? 0),
                'average_check' => (float) (data_get($customer, 'average_check') ?? 0),
                'ltv' => (float) (data_get($customer, 'ltv') ?? 0),
                'next_price' => (float) (data_get($customer, 'next_price') ?? 0),
                'next_date' => $this->toDateTime(data_get($customer, 'next_date'))?->toDateTimeString(),
                'metadata' => [
                    'amo_customer' => $customer,
                ],
            ]);
            $pulled++;
            $buyerModel->wasRecentlyCreated ? $created++ : $updated++;

            $catalogElement = data_get($customer, '_embedded.catalog_elements.0');

            if (is_array($catalogElement)) {
                $productModel = $this->upsertLinkedProduct(
                    $connection,
                    'customer',
                    $customer,
                    $catalogElement,
                );
                $pulled++;
                $productModel->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $companySyncStats = $this->syncLocalCompaniesToAmo($settings);
        $pulled += $companySyncStats['pulled'];
        $created += $companySyncStats['created'];
        $updated += $companySyncStats['updated'];
        $warnings = array_merge($warnings, $companySyncStats['warnings']);

        $companies = Client::query()
            ->where('source_type', 'amo_company')
            ->whereNotNull('external_id')
            ->get();

        foreach ($companies as $companyModel) {

            try {
                $company = $this->fetchAmoEntity($settings, '/api/v4/companies/'.$companyModel->external_id);

                $companyModel->name = (string) data_get($company, 'name', $companyModel->name);
                $companyModel->metadata = array_merge($companyModel->metadata ?? [], [
                    'amo_company' => $company,
                ]);
                $companyModel->save();
                $pulled++;
                $updated++;
            } catch (Throwable $throwable) {
                report($throwable);
                $warnings[] = 'Компания '.$companyModel->external_id.': '.$throwable->getMessage();
            }
        }

        $products = EntityProduct::query()
            ->where(function ($query) {
                $query
                    ->whereNull('product_name')
                    ->orWhere('product_name', '')
                    ->orWhereNull('category')
                    ->orWhereNull('total_amount')
                    ->orWhere('total_amount', '<=', 0);
            })
            ->get();
        $productDetails = $this->getProducts($products->pluck('external_id')->filter()->unique()->all(), $settings);

        foreach ($products as $productModel) {

            try {
                $product = $productDetails[(string) $productModel->external_id] ?? null;

                if (! is_array($product)) {
                    continue;
                }

                $productModel->forceFill([
                    'product_name' => $product['name'] ?? $productModel->product_name,
                    'total_amount' => $this->getAmoFieldValueByName($product, 'Цена', $productModel->total_amount),
                    'category' => $this->getAmoFieldValueByName($product, 'Группа', $productModel->category),
                    'metadata' => $product,
                ]);

                $productModel->save();
                $pulled++;
                $updated++;
            } catch (\Throwable $e) {
                report($e);
                $warnings[] = 'Услуга '.$productModel->external_id.': '.$e->getMessage();

            }
        }

        $invoiceStats = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'skipped' => true];

        if ((bool) ($settings['sync_invoices'] ?? false)) {
            try {
                $amoApi = $this->buildAmoClient($settings);

                if (! $amoApi) {
                    throw new \RuntimeException('Не удалось создать клиент amoCRM');
                }

                $invoiceStats = $this->syncInvoices($amoApi, $connection, $settings);
            } catch (Throwable $throwable) {
                report($throwable);
                $warnings[] = 'Счета: '.$throwable->getMessage();
                $invoiceStats = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'failed' => true];
            }
        }

        return SyncResult::ok(
            $pulled + ($invoiceStats['pulled'] ?? 0),
            $created + ($invoiceStats['created'] ?? 0),
            $updated + ($invoiceStats['updated'] ?? 0),
            ['invoices' => $invoiceStats, 'warnings' => $warnings]
        );
    }

    public function updateInvoicePaymentStatus(SourceConnection $connection, Invoice $invoice, string $status): void
    {
        $settings = $this->resolveSettings($connection);
        $amoApi = $this->buildAmoClient($settings);

        if (! $amoApi) {
            throw new \RuntimeException('Unable to build amoCRM client');
        }

        $catalogId = (int) data_get($settings, 'invoice_catalog_id', config('services.amo.invoice_catalog_id', 3135));
        $statusFieldId = (int) data_get($settings, 'invoice_status_field_id', config('services.amo.invoice_status_field_id', 169931));

        $query = $amoApi->query('PATCH', '/api/v4/catalogs/'.$catalogId.'/elements');
        $query->setJsonData([
            [
                'id' => (int) $invoice->external_id,
                'custom_fields_values' => [
                    [
                        'field_id' => $statusFieldId,
                        'values' => [
                            [
                                'value' => $status,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $query->execute();
        $query->response->validatedUpdatedEntities('elements');
    }

    public function syncInvoicesOnly(SourceConnection $connection): SyncResult
    {
        $settings = $this->resolveSettings($connection);

        if (! $this->isConfigured($settings)) {
            return SyncResult::fail('amoCRM is not configured');
        }

        $amoApi = $this->buildAmoClient($settings);

        if (! $amoApi) {
            return SyncResult::fail('Unable to build amoCRM client');
        }

        $invoiceStats = $this->syncInvoices($amoApi, $connection, $settings);

        return SyncResult::ok(
            $invoiceStats['pulled'] ?? 0,
            $invoiceStats['created'] ?? 0,
            $invoiceStats['updated'] ?? 0,
            ['invoices' => $invoiceStats]
        );
    }

    function getAmoFieldValueByName(array $element, string $fieldName, mixed $default = null): mixed
    {
        foreach (($element['custom_fields_values'] ?? []) as $field) {
            if (($field['field_name'] ?? null) !== $fieldName) {
                continue;
            }

            $values = $field['values'] ?? [];

            if ($values === []) {
                return $default;
            }

            return $values[0]['value'] ?? $default;
        }

        return $default;
    }

    /**
     * @throws \Exception
     */
    protected function buildAmoClient(array $settings): ?ApiClient
    {
        $domain = $this->amoDomain($settings);
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $token = trim((string) ($settings['access_token'] ?? ''));

        if ($domain === null || $clientId === '' || $token === '') {
            return null;
        }

        $client = ApiClient::setInstance([
            'client_id' => $clientId,
            'domain' => $domain,
            'zone' => $this->amoZone($settings),
        ]);

        $client->oauth->setLongToken($token);

        return $client;
    }

    protected function amoDomain(array $settings): ?string
    {
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));

        if ($baseUrl === '') {
            return null;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $parts = array_values(array_filter(explode('.', $host)));

        return $parts[0] ?? $host;
    }

    protected function amoZone(array $settings): string
    {
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;

        return Str::contains((string) $host, 'kommo.com') ? 'com' : 'ru';
    }

    protected function resolveSettings(SourceConnection $connection): array
    {
        return array_merge(
            config('services.amo') ?? [],
            $this->filledSettings(is_array($connection->settings ?? null) ? $connection->settings : [])
        );
    }

    protected function isConfigured(array $settings): bool
    {
        return filled($settings['base_url'] ?? null) && filled($settings['access_token'] ?? null);
    }

    protected function upsertAmoCompanyClient(mixed $company, ?string $fallbackName = null): Client
    {
        $company = $this->normalizeAmoEntity($company);
        $externalId = (string) data_get($company, 'id', '');
        $name = trim((string) data_get($company, 'name', $fallbackName ?: 'amoCRM company '.$externalId));
        $inn = $this->extractAmoCompanyInn($company);

        $client = Client::query()
            ->where('source_type', 'amo_company')
            ->where('external_id', $externalId)
            ->first();

        if (! $client && $inn !== null) {
            $client = Client::query()->where('inn', $inn)->first();
        }

        $client ??= new Client();
        $metadata = $client->metadata ?? [];

        $client->forceFill([
            'source_type' => 'amo_company',
            'external_id' => $externalId,
            'name' => $name !== '' ? $name : 'amoCRM company '.$externalId,
            'legal_name' => $client->legal_name ?: ($name !== '' ? $name : null),
            'inn' => $inn ?? $client->inn,
            'category' => 'company',
            'status' => 'active',
            'metadata' => array_merge(is_array($metadata) ? $metadata : [], [
                'amo_company' => $company,
            ]),
        ]);

        $client->save();

        return $client;
    }

    /**
     * @param  iterable<Client>  $clients
     * @return array{pulled:int, created:int, updated:int, skipped:int, tagged:int, metrics_updated:int, warnings:array<int, string>}
     */
    public function syncClientsToAmoByInn(SourceConnection $connection, iterable $clients, string $tagName = 'Точка', bool $dryRun = false, array $clientMetrics = []): array
    {
        $settings = $this->resolveSettings($connection);

        if (! $this->isConfigured($settings)) {
            throw new \RuntimeException('amoCRM is not configured');
        }

        $stats = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'tagged' => 0, 'metrics_updated' => 0, 'warnings' => []];

        foreach ($clients as $client) {
            if (! $client instanceof Client) {
                continue;
            }

            $stats['pulled']++;
            $inn = $this->normalizeInn($client->inn);

            if ($inn === null) {
                $stats['skipped']++;
                $stats['warnings'][] = 'Client '.$client->id.' skipped: no valid INN';
                continue;
            }

            try {
                $amoCompany = $this->findAmoCompanyByInn($settings, $inn);
                $createdInAmo = false;

                if (! $amoCompany) {
                    if ($dryRun) {
                        $stats['created']++;
                        continue;
                    }

                    $amoCompany = $this->createAmoCompanyFromClient($settings, $client, $inn, $clientMetrics[$client->id] ?? null);
                    $createdInAmo = true;
                } elseif ($dryRun) {
                    $stats['updated']++;
                    continue;
                }

                $companyId = (string) data_get($amoCompany, 'id', '');

                if ($companyId === '') {
                    throw new \RuntimeException('amoCRM company id is empty for INN '.$inn);
                }

                if ($this->ensureAmoCompanyTag($settings, $companyId, $tagName)) {
                    $stats['tagged']++;
                }

                $displayName = $this->shortCompanyName((string) ($client->legal_name ?: $client->name));

                $this->updateAmoCompanyName($settings, $companyId, $displayName);

                if ($this->updateAmoCompanyMetrics($settings, $companyId, $client, $clientMetrics[$client->id] ?? null)) {
                    $stats['metrics_updated']++;
                }

                $client->forceFill([
                    'source_type' => 'amo_company',
                    'external_id' => $companyId,
                    'name' => $displayName,
                    'legal_name' => $client->legal_name ?: $client->name,
                    'inn' => $inn,
                    'category' => 'company',
                    'status' => 'active',
                    'metadata' => array_merge(is_array($client->metadata) ? $client->metadata : [], [
                        'amo_company' => $amoCompany,
                        'amo_company_synced_at' => now()->toDateTimeString(),
                        'amo_company_sync_tag' => $tagName,
                    ]),
                ]);
                $client->save();

                $createdInAmo ? $stats['created']++ : $stats['updated']++;
            } catch (Throwable $throwable) {
                report($throwable);
                $stats['warnings'][] = 'Client '.$client->id.' INN '.$inn.': '.$throwable->getMessage();
            }
        }

        return $stats;
    }

    /**
     * @return array{pulled:int, created:int, updated:int, warnings:array<int, string>}
     */
    protected function syncLocalCompaniesToAmo(array $settings): array
    {
        $stats = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'warnings' => []];

        $clients = Client::query()
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->where(function ($query) {
                $query
                    ->whereNull('external_id')
                    ->orWhere('external_id', '')
                    ->orWhere('source_type', '!=', 'amo_company')
                    ->orWhereNull('source_type');
            })
            ->orderBy('name')
            ->get();

        foreach ($clients as $client) {
            $inn = $this->normalizeInn($client->inn);

            if ($inn === null) {
                continue;
            }

            try {
                $amoCompany = $this->findAmoCompanyByInn($settings, $inn);
                $createdInAmo = false;

                if (! $amoCompany) {
                    $amoCompany = $this->createAmoCompanyFromClient($settings, $client, $inn);
                    $createdInAmo = true;
                }

                $client->forceFill([
                    'source_type' => 'amo_company',
                    'external_id' => (string) data_get($amoCompany, 'id', $client->external_id),
                    'name' => trim((string) data_get($amoCompany, 'name', $client->name)) ?: $client->name,
                    'legal_name' => $client->legal_name ?: $client->name,
                    'inn' => $inn,
                    'category' => 'company',
                    'status' => 'active',
                    'metadata' => array_merge(is_array($client->metadata) ? $client->metadata : [], [
                        'amo_company' => $amoCompany,
                        'amo_company_synced_at' => now()->toDateTimeString(),
                    ]),
                ]);
                $client->save();

                $stats['pulled']++;
                $createdInAmo ? $stats['created']++ : $stats['updated']++;
            } catch (Throwable $throwable) {
                report($throwable);
                $stats['warnings'][] = 'Company INN '.$inn.': '.$throwable->getMessage();
            }
        }

        return $stats;
    }

    protected function findAmoCompanyByInn(array $settings, string $inn): ?array
    {
        $innFieldId = $this->companyInnFieldId($settings);

        if ($innFieldId !== null) {
            try {
                $companies = $this->fetchAmoCollection($settings, '/api/v4/companies', [
                    'filter' => [
                        'custom_fields_values' => [
                            $innFieldId => [$inn],
                        ],
                    ],
                ], 'companies');

                foreach ($companies as $company) {
                    if ($this->extractAmoCompanyInn($company) === $inn) {
                        return $company;
                    }
                }
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }

        $companies = $this->fetchAmoCollection($settings, '/api/v4/companies', [
            'query' => $inn,
        ], 'companies');

        foreach ($companies as $company) {
            if ($this->extractAmoCompanyInn($company) === $inn) {
                return $company;
            }
        }

        return null;
    }

    protected function createAmoCompanyFromClient(array $settings, Client $client, string $inn, ?array $metrics = null): array
    {
        $baseUrl = rtrim((string) ($settings['base_url'] ?? config('services.amo.base_url')), '/');
        $token = trim((string) ($settings['access_token'] ?? config('services.amo.access_token')));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('amoCRM is not configured');
        }

        $company = [
            'name' => $this->shortCompanyName((string) ($client->legal_name ?: $client->name ?: 'Компания '.$inn)),
        ];
        $innFieldId = $this->companyInnFieldId($settings);

        if ($innFieldId === null) {
            throw new \RuntimeException('amoCRM company INN custom field was not found. Set AMO_COMPANY_INN_FIELD_ID or AMO_COMPANY_INN_FIELD_NAME.');
        }

        $company['custom_fields_values'] = [
            [
                'field_id' => $innFieldId,
                'values' => [
                    ['value' => $inn],
                ],
            ],
        ];
        $company['custom_fields_values'] = array_merge(
            $company['custom_fields_values'],
            $this->companyMetricFieldValues($settings, $client, $metrics),
        );

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->post($baseUrl.'/api/v4/companies', [$company]);

        $response->throw();

        $created = data_get($response->json(), '_embedded.companies.0', []);

        if (! is_array($created) || ! filled(data_get($created, 'id'))) {
            throw new \RuntimeException('amoCRM did not return created company id');
        }

        return $created;
    }

    protected function updateAmoCompanyName(array $settings, string $companyId, string $name): bool
    {
        $baseUrl = rtrim((string) ($settings['base_url'] ?? config('services.amo.base_url')), '/');
        $token = trim((string) ($settings['access_token'] ?? config('services.amo.access_token')));
        $name = trim($name);

        if ($baseUrl === '' || $token === '' || $companyId === '' || $name === '') {
            return false;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->patch($baseUrl.'/api/v4/companies/'.$companyId, [
                'name' => $name,
            ]);

        $response->throw();

        return true;
    }

    protected function shortCompanyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?: $name);

        $name = preg_replace('/\s*\((?:ИП|ООО)\)\s*$/ui', '', $name) ?: $name;

        if (preg_match('/^\s*индивидуальный\s+предприниматель\s+(.+)$/ui', $name, $matches)) {
            return trim('ИП '.$matches[1]);
        }

        if (preg_match('/^\s*ип\s+(.+)$/ui', $name, $matches)) {
            return trim('ИП '.$matches[1]);
        }

        if (preg_match('/^\s*общество\s+с\s+ограниченной\s+ответственностью\s+(.+)$/ui', $name, $matches)) {
            return trim('ООО '.$matches[1]);
        }

        if (preg_match('/^\s*ооо\s+(.+)$/ui', $name, $matches)) {
            return trim('ООО '.$matches[1]);
        }

        return trim(preg_replace('/\s+/u', ' ', $name) ?: $name);
    }

    protected function updateAmoCompanyMetrics(array $settings, string $companyId, Client $client, ?array $metrics = null): bool
    {
        $baseUrl = rtrim((string) ($settings['base_url'] ?? config('services.amo.base_url')), '/');
        $token = trim((string) ($settings['access_token'] ?? config('services.amo.access_token')));
        $fieldValues = $this->companyMetricFieldValues($settings, $client, $metrics);

        if ($baseUrl === '' || $token === '' || $companyId === '' || $fieldValues === []) {
            return false;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->patch($baseUrl.'/api/v4/companies/'.$companyId, [
                'custom_fields_values' => $fieldValues,
            ]);

        $response->throw();

        return true;
    }

    /**
     * @return array<int, array{field_id:int, values:array<int, array{value:string}>}>
     */
    protected function companyMetricFieldValues(array $settings, Client $client, ?array $metrics = null): array
    {
        $metrics = $this->companyMetrics($client, $metrics);
        $fields = [];
        $ltvFieldId = $this->companyLtvFieldId($settings);
        $salesAmountFieldId = $this->companySalesAmountFieldId($settings);
        $averageCheckFieldId = $this->companyAverageCheckFieldId($settings);
        $salesCountFieldId = $this->companySalesCountFieldId($settings);
        $usedFieldIds = [];

        if ($ltvFieldId !== null) {
            $fields[] = [
                'field_id' => $ltvFieldId,
                'values' => [
                    ['value' => (string) (int) round($metrics['average_check'])],
                ],
            ];
            $usedFieldIds[] = $ltvFieldId;
        }

        if ($salesAmountFieldId !== null && ! in_array($salesAmountFieldId, $usedFieldIds, true)) {
            $fields[] = [
                'field_id' => $salesAmountFieldId,
                'values' => [
                    ['value' => (string) (int) round($metrics['ltv'])],
                ],
            ];
            $usedFieldIds[] = $salesAmountFieldId;
        }

        if ($salesCountFieldId !== null && ! in_array($salesCountFieldId, $usedFieldIds, true)) {
            $fields[] = [
                'field_id' => $salesCountFieldId,
                'values' => [
                    ['value' => (string) $metrics['sales_count']],
                ],
            ];
            $usedFieldIds[] = $salesCountFieldId;
        }

        if ($averageCheckFieldId !== null && ! in_array($averageCheckFieldId, $usedFieldIds, true)) {
            $fields[] = [
                'field_id' => $averageCheckFieldId,
                'values' => [
                    ['value' => (string) (int) round($metrics['average_check'])],
                ],
            ];
        }

        return $fields;
    }

    /**
     * @return array{ltv:float, sales_count:int, average_check:float}
     */
    protected function companyMetrics(Client $client, ?array $metrics = null): array
    {
        if (is_array($metrics)) {
            $ltv = (float) ($metrics['ltv'] ?? 0);
            $salesCount = (int) ($metrics['sales_count'] ?? 0);

            return [
                'ltv' => $ltv,
                'sales_count' => $salesCount,
                'average_check' => $salesCount > 0 ? $ltv / $salesCount : 0,
            ];
        }

        $row = RevenueTransaction::query()
            ->where('client_id', $client->id)
            ->selectRaw('coalesce(sum(amount), 0) as ltv, count(*) as sales_count')
            ->first();

        return [
            'ltv' => (float) ($row?->ltv ?? 0),
            'sales_count' => (int) ($row?->sales_count ?? 0),
            'average_check' => (int) ($row?->sales_count ?? 0) > 0 ? (float) ($row?->ltv ?? 0) / (int) ($row?->sales_count ?? 0) : 0,
        ];
    }

    protected function ensureAmoCompanyTag(array $settings, string $companyId, string $tagName): bool
    {
        $baseUrl = rtrim((string) ($settings['base_url'] ?? config('services.amo.base_url')), '/');
        $token = trim((string) ($settings['access_token'] ?? config('services.amo.access_token')));
        $tagName = trim($tagName);

        if ($baseUrl === '' || $token === '' || $companyId === '' || $tagName === '') {
            return false;
        }

        $company = $this->fetchAmoEntity($settings, '/api/v4/companies/'.$companyId, ['with' => 'tags']);
        $tags = collect(data_get($company, '_embedded.tags', []))
            ->filter(fn ($tag): bool => is_array($tag))
            ->map(function (array $tag): array {
                $id = (int) data_get($tag, 'id', 0);
                $name = trim((string) data_get($tag, 'name', ''));

                return array_filter([
                    'id' => $id > 0 ? $id : null,
                    'name' => $name !== '' ? $name : null,
                ], fn ($value): bool => filled($value));
            })
            ->filter(fn (array $tag): bool => filled($tag['id'] ?? null) || filled($tag['name'] ?? null))
            ->values();

        if ($tags->contains(fn (array $tag): bool => Str::lower((string) ($tag['name'] ?? '')) === Str::lower($tagName))) {
            return false;
        }

        $tags->push(['name' => $tagName]);

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->patch($baseUrl.'/api/v4/companies/'.$companyId, [
                '_embedded' => [
                    'tags' => $tags->values()->all(),
                ],
            ]);

        $response->throw();

        return true;
    }

    protected function companyInnFieldId(array $settings): ?int
    {
        if ($this->companyInnFieldId !== null) {
            return $this->companyInnFieldId;
        }

        $configured = (int) ($settings['company_inn_field_id'] ?? config('services.amo.company_inn_field_id'));

        if ($configured > 0) {
            return $this->companyInnFieldId = $configured;
        }

        $fieldName = Str::lower((string) ($settings['company_inn_field_name'] ?? config('services.amo.company_inn_field_name', 'ИНН')));
        $payload = $this->fetchAmoEntity($settings, '/api/v4/companies/custom_fields');
        $fields = data_get($payload, '_embedded.custom_fields', []);

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            $name = Str::lower((string) data_get($field, 'name', data_get($field, 'field_name', '')));
            $code = Str::lower((string) data_get($field, 'code', data_get($field, 'field_code', '')));

            if ($name === $fieldName || $name === 'инн' || Str::contains($name, 'инн') || $code === 'inn') {
                $id = (int) data_get($field, 'id', data_get($field, 'field_id', 0));

                return $this->companyInnFieldId = $id > 0 ? $id : null;
            }
        }

        return null;
    }

    protected function companyLtvFieldId(array $settings): ?int
    {
        if ($this->companyLtvFieldId !== null) {
            return $this->companyLtvFieldId;
        }

        $configured = (int) ($settings['company_ltv_field_id'] ?? config('services.amo.company_ltv_field_id'));

        if ($configured > 0) {
            return $this->companyLtvFieldId = $configured;
        }

        return $this->companyLtvFieldId = $this->findAmoCompanyCustomFieldId($settings, [
            (string) ($settings['company_ltv_field_name'] ?? config('services.amo.company_ltv_field_name', 'LTV')),
            'LTV',
            'ЛТВ',
        ], ['ltv']);
    }

    protected function companySalesAmountFieldId(array $settings): ?int
    {
        if ($this->companySalesAmountFieldId !== null) {
            return $this->companySalesAmountFieldId;
        }

        $configured = (int) ($settings['company_sales_amount_field_id'] ?? config('services.amo.company_sales_amount_field_id'));

        if ($configured > 0) {
            return $this->companySalesAmountFieldId = $configured;
        }

        return $this->companySalesAmountFieldId = $this->findAmoCompanyCustomFieldId($settings, [
            (string) ($settings['company_sales_amount_field_name'] ?? config('services.amo.company_sales_amount_field_name', 'Сумма продаж')),
            'Сумма продаж',
            'Выручка',
            'Сумма оплат',
            'Оплачено',
        ], ['sales_amount', 'sales_sum', 'revenue_amount', 'payments_amount']);
    }

    protected function companySalesCountFieldId(array $settings): ?int
    {
        if ($this->companySalesCountFieldId !== null) {
            return $this->companySalesCountFieldId;
        }

        $configured = (int) ($settings['company_sales_count_field_id'] ?? config('services.amo.company_sales_count_field_id'));

        if ($configured > 0) {
            return $this->companySalesCountFieldId = $configured;
        }

        return $this->companySalesCountFieldId = $this->findAmoCompanyCustomFieldId($settings, [
            (string) ($settings['company_sales_count_field_name'] ?? config('services.amo.company_sales_count_field_name', 'Количество продаж')),
            'Количество продаж',
            'Кол-во продаж',
            'Продаж',
            'Количество оплат',
            'Оплат',
        ], ['sales_count', 'purchases_count', 'payments_count']);
    }

    protected function companyAverageCheckFieldId(array $settings): ?int
    {
        if ($this->companyAverageCheckFieldId !== null) {
            return $this->companyAverageCheckFieldId;
        }

        $configured = (int) ($settings['company_average_check_field_id'] ?? config('services.amo.company_average_check_field_id'));

        if ($configured > 0) {
            return $this->companyAverageCheckFieldId = $configured;
        }

        return $this->companyAverageCheckFieldId = $this->findAmoCompanyCustomFieldId($settings, [
            (string) ($settings['company_average_check_field_name'] ?? config('services.amo.company_average_check_field_name', 'Средний чек')),
            'Средний чек',
            'Средняя продажа',
            'Средняя сумма продажи',
            'Средняя сумма оплаты',
        ], ['average_check', 'avg_check', 'average_sale', 'avg_sale']);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $codes
     */
    protected function findAmoCompanyCustomFieldId(array $settings, array $names, array $codes = []): ?int
    {
        $payload = $this->fetchAmoEntity($settings, '/api/v4/companies/custom_fields');
        $fields = data_get($payload, '_embedded.custom_fields', []);

        if (! is_array($fields)) {
            return null;
        }

        $names = collect($names)
            ->map(fn (string $name): string => Str::lower(trim($name)))
            ->filter()
            ->unique()
            ->values();
        $codes = collect($codes)
            ->map(fn (string $code): string => Str::lower(trim($code)))
            ->filter()
            ->unique()
            ->values();

        foreach ($fields as $field) {
            $name = Str::lower((string) data_get($field, 'name', data_get($field, 'field_name', '')));
            $code = Str::lower((string) data_get($field, 'code', data_get($field, 'field_code', '')));

            if ($names->contains($name) || $codes->contains($code)) {
                $id = (int) data_get($field, 'id', data_get($field, 'field_id', 0));

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    protected function extractAmoCompanyInn(array $company): ?string
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

    protected function normalizeInn(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return in_array(strlen($digits), [10, 12], true) ? $digits : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAmoCollection(array $settings, string $path, array $query, string $embeddedKey): array
    {
        $items = [];
        $page = 1;
        $limit = 250;

        do {
            $payload = $this->fetchAmoEntity($settings, $path, array_merge($query, [
                'page' => $page,
                'limit' => $limit,
            ]));

            $batch = data_get($payload, '_embedded.'.$embeddedKey, []);

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $hasNextPage = filled(data_get($payload, '_links.next.href'));
            $page++;
        } while ($hasNextPage && $page <= 100);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAmoEntity(array $settings, string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) ($settings['base_url'] ?? config('services.amo.base_url')), '/');
        $token = trim((string) ($settings['access_token'] ?? config('services.amo.access_token')));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('amoCRM is not configured');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->get($baseUrl.$path, $query);

        $response->throw();

        return $response->json() ?? [];
    }

    protected function filledSettings(array $settings): array
    {
        return array_filter($settings, fn ($value): bool => filled($value));
    }

    protected function upsertLinkedProduct(
        SourceConnection $connection,
        string $entityType,
        array $entity,
        array $catalogElement,
    ): EntityProduct {
        $productExternalId = (string) data_get($catalogElement, 'id', data_get($catalogElement, 'to_entity_id', ''));
        $entityExternalId = (string) data_get($entity, 'id', '');
        $entityName = trim((string) data_get($entity, 'name', $entityType.' #'.$entityExternalId));
        $quantity = (float) data_get($catalogElement, 'metadata.quantity', data_get($catalogElement, 'quantity', 1));
        $entityDate = $this->toDateTime(data_get($entity, 'closed_at', data_get($entity, 'created_at')));

        return EntityProduct::query()->updateOrCreate(
            [
                'source_key' => $connection->source_key,
                'external_id' => $productExternalId,
                'entity_external_id' => $entityExternalId,
                'entity_type' => $entityType,
            ],
            [
                'source_connection_id' => $connection->id,
                'source_type' => $connection->driver,
                'entity_name' => $entityName !== '' ? $entityName : $entityType.' #'.$entityExternalId,
                'entity_date' => $entityDate?->toDateTimeString(),
                'product_external_id' => $productExternalId !== '' ? $productExternalId : null,
                'product_name' => trim((string) data_get($catalogElement, 'name', 'Услуга #'.$productExternalId)),
                'quantity' => $quantity,
                'unit_price' => (float) data_get($catalogElement, 'price', data_get($catalogElement, 'unit_price', 0)),
                'total_amount' => (float) data_get($catalogElement, 'total', data_get($catalogElement, 'total_price', 0)),
                'metadata' => [
                    'amo_entity' => $entity,
                    'amo_catalog_element' => $catalogElement,
                ],
            ],
        );
    }

    /**
     * @param  array<int, string|int>  $productIds
     * @return array<string, array<string, mixed>>
     */
    protected function getProducts(array $productIds, array $settings): array
    {
        $ids = collect($productIds)
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $baseUrl = rtrim((string) ($settings['base_url'] ?? config('services.amo.base_url')), '/');
        $token = trim((string) ($settings['access_token'] ?? config('services.amo.access_token')));

        if ($baseUrl === '' || $token === '') {
            return [];
        }

        $products = [];

        foreach ($ids->chunk(100) as $chunk) {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(20)
                    ->get($baseUrl.'/api/v4/catalogs/5655/elements', [
                        'filter' => [
                            'id' => $chunk->values()->all(),
                        ],
                        'limit' => $chunk->count(),
                    ]);

                $response->throw();

                $elements = data_get($response->json(), '_embedded.elements', []);

                if (! is_array($elements)) {
                    continue;
                }

                foreach ($elements as $element) {
                    $id = (string) data_get($element, 'id', '');

                    if ($id === '') {
                        continue;
                    }

                    $products[$id] = $element;
                    $this->catalogElementCache[$id] = $element;
                }
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }

        return $products;
    }

    /**
     * @return array{pulled:int, created:int, updated:int}
     */
    protected function syncInvoices(ApiClient $amoApi, SourceConnection $connection, array $settings): array
    {
        $catalogId = (string) data_get($settings, 'invoice_catalog_id', config('services.amo.invoice_catalog_id', 3135));
        $invoiceStatusFieldId = (int) data_get($settings, 'invoice_status_field_id', config('services.amo.invoice_status_field_id', 169931));
        $invoiceAmountFieldId = (int) data_get($settings, 'invoice_amount_field_id', config('services.amo.invoice_amount_field_id', 169893));
        $invoiceVatFieldId = (int) data_get($settings, 'invoice_vat_field_id', config('services.amo.invoice_vat_field_id', 169889));
        $invoiceGroupFieldId = (int) data_get($settings, 'invoice_group_field_id', config('services.amo.invoice_group_field_id', 431159));
        $invoicePaymentHashFieldId = (int) data_get($settings, 'invoice_payment_hash_field_id', config('services.amo.invoice_payment_hash_field_id', 458745));
        $invoiceItemsFieldId = (int) data_get($settings, 'invoice_items_field_id', config('services.amo.invoice_items_field_id', 169939));

        $customers = $amoApi->customers()->get(['links']);
        $seenInvoices = [];
        $pulled = 0;
        $created = 0;
        $updated = 0;

        foreach ($customers as $customer) {
            $customerData = $this->normalizeAmoEntity($customer);
            $customerId = (string) data_get($customerData, 'id', '');
            $customerName = trim((string) data_get($customerData, 'name', ''));

            foreach ($this->customerLinks($amoApi, $customerData) as $link) {
                if ((string) data_get($link, 'to_entity_type', '') !== 'catalog_elements') {
                    continue;
                }

                if ((string) data_get($link, 'metadata.catalog_id', '') !== $catalogId) {
                    continue;
                }

                $elementId = (string) data_get($link, 'to_entity_id', '');

                if ($elementId === '' || isset($seenInvoices[$elementId])) {
                    continue;
                }

                $seenInvoices[$elementId] = true;

                $details = $this->resolveCatalogElementDetails($amoApi, [
                    'id' => $elementId,
                    'catalog_id' => $catalogId,
                    'metadata' => [
                        'catalog_id' => $catalogId,
                    ],
                ]);

                if ($details === []) {
                    continue;
                }

                $invoiceName = trim((string) data_get($details, 'name', ''));

                if ($invoiceName === '') {
                    $invoiceName = 'Счет #'.$elementId;
                }

                $invoiceStatus = trim((string) $this->catalogFieldValue($details, ['BILL_STATUS', 'STATUS'], 'Не указан'));
                $invoiceAmount = (float) $this->catalogFieldValue($details, ['BILL_PRICE', 'PRICE'], data_get($details, 'price', 0));
                $invoiceVatType = trim((string) $this->catalogFieldValue($details, ['BILL_VAT_TYPE'], ''));
                $invoiceGroup = trim((string) $this->catalogFieldValue($details, ['GROUP'], ''));
                $paymentHash = trim((string) $this->catalogFieldValue($details, ['INVOICE_HASH_LINK'], ''));
                $invoiceLink = trim((string) data_get($details, 'invoice_link', ''));
                $invoiceDate = $this->toDateTime(data_get($details, 'created_at', data_get($details, 'updated_at')));
                $items = data_get($details, '_embedded.catalog_elements', data_get($details, 'custom_fields_values', []));

                $invoice = Invoice::query()->updateOrCreate(
                    [
                        'source_connection_id' => $connection->id,
                        'external_id' => $elementId,
                    ],
                    [
                        'source_key' => $connection->source_key,
                        'source_type' => $connection->driver,
                        'catalog_id' => (int) $catalogId,
                        'name' => $invoiceName,
                        'customer_external_id' => $customerId !== '' ? $customerId : null,
                        'customer_name' => $customerName !== '' ? $customerName : null,
                        'category' => $invoiceGroup !== '' ? $invoiceGroup : null,
                        'payment_status' => $invoiceStatus !== '' ? $invoiceStatus : 'Не указан',
                        'payment_status_enum_id' => null,
                        'amount' => $invoiceAmount,
                        'vat_type' => $invoiceVatType !== '' ? $invoiceVatType : null,
                        'payment_hash' => $paymentHash !== '' ? $paymentHash : null,
                        'invoice_link' => $invoiceLink !== '' ? $invoiceLink : null,
                        'invoice_date' => $invoiceDate,
                        'metadata' => [
                            'amo_customer' => $customerData,
                            'amo_invoice' => $details,
                            'invoice_status_field_id' => $invoiceStatusFieldId,
                            'invoice_amount_field_id' => $invoiceAmountFieldId,
                            'invoice_vat_field_id' => $invoiceVatFieldId,
                            'invoice_group_field_id' => $invoiceGroupFieldId,
                            'invoice_payment_hash_field_id' => $invoicePaymentHashFieldId,
                            'invoice_items_field_id' => $invoiceItemsFieldId,
                            'invoice_items' => is_array($items) ? $items : [],
                        ],
                    ]
                );

                $pulled++;

                if ($invoice->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        }

        return compact('pulled', 'created', 'updated');
    }

    /**
     * @param  mixed  $customerData
     * @return array<int, array<string, mixed>>
     */
    protected function customerLinks(ApiClient $amoApi, mixed $customerData): array
    {
        $embeddedLinks = data_get($customerData, '_embedded.links', data_get($customerData, 'links', []));

        if (is_array($embeddedLinks) && $embeddedLinks !== []) {
            return $embeddedLinks;
        }

        $customerId = (string) data_get($customerData, 'id', '');

        if ($customerId === '') {
            return [];
        }

        try {
            $query = $amoApi->query('GET', '/api/v4/customers/'.$customerId.'/links');
            $query->execute();
            $payload = $this->normalizeAmoEntity($query->response->validated());

            $links = data_get($payload, '_embedded.links', []);

            return is_array($links) ? $links : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  mixed  $entity
     * @return array<string, mixed>
     */
    protected function normalizeAmoEntity(mixed $entity): array
    {
        if (is_array($entity)) {
            return $entity;
        }

        if (is_object($entity) && method_exists($entity, 'toArray')) {
            $payload = $entity->toArray();

            return is_array($payload) ? $payload : [];
        }

        if (is_object($entity)) {
            return get_object_vars($entity);
        }

        return [];
    }

    /**
     * @param  mixed  $catalogElements
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeCatalogElements(mixed $catalogElements): array
    {
        if (! is_array($catalogElements)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $item): array {
            if (is_array($item)) {
                return $item;
            }

            if (is_object($item) && method_exists($item, 'toArray')) {
                $payload = $item->toArray();

                return is_array($payload) ? $payload : [];
            }

            if (is_object($item)) {
                return get_object_vars($item);
            }

            return [];
        }, $catalogElements)));
    }

    protected function pipelineForAmoLead(array $lead): ?Pipeline
    {
        $externalId = trim((string) data_get($lead, 'pipeline_id', ''));

        if ($externalId === '') {
            return null;
        }

        if (array_key_exists($externalId, $this->pipelineCache)) {
            return $this->pipelineCache[$externalId];
        }

        return $this->pipelineCache[$externalId] = Pipeline::query()
            ->where('external_id', $externalId)
            ->first();
    }

    protected function stageForAmoLead(array $lead, ?Pipeline $pipeline): ?Stage
    {
        $externalId = trim((string) data_get($lead, 'status_id', ''));

        if ($externalId === '') {
            return null;
        }

        $cacheKey = ($pipeline?->id ?? 'none').':'.$externalId;

        if (array_key_exists($cacheKey, $this->stageCache)) {
            return $this->stageCache[$cacheKey];
        }

        $query = Stage::query()->where('external_id', $externalId);

        if ($pipeline !== null) {
            $query->where('pipeline_id', $pipeline->id);
        }

        return $this->stageCache[$cacheKey] = $query->first();
    }

    protected function statusForStage(?Stage $stage, mixed $statusId): string
    {
        $externalId = (string) $statusId;

        if ($stage?->is_success || $externalId === '142') {
            return 'won';
        }

        if ($stage?->is_failure || $externalId === '143') {
            return 'lost';
        }

        return 'open';
    }

    protected function leadSourceChannel(array $leadData): ?string
    {
        $fields = data_get($leadData, 'custom_fields_values', data_get($leadData, '_embedded.custom_fields_values', []));

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            $fieldId = (string) data_get($field, 'field_id');
            $fieldName = Str::lower(trim((string) data_get($field, 'field_name', '')));

            if ($fieldId !== '431237' && $fieldName !== Str::lower('Источник')) {
                continue;
            }

            $values = data_get($field, 'values', []);

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $candidate = trim((string) data_get($value, 'value', ''));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    protected function syncEntityProducts(
        ApiClient $amoApi,
        string $sourceKey,
        string $entityType,
        string $entityExternalId,
        string $entityName,
        CarbonImmutable $entityDate,
        mixed $catalogElements,
        array $rawEntity = [],
    ): int {
        $items = $this->normalizeCatalogElements($catalogElements);

        EntityProduct::query()
            ->where('source_key', $sourceKey)
            ->where('entity_type', $entityType)
            ->where('entity_external_id', $entityExternalId)
            ->delete();

        if ($items === []) {
            return 0;
        }

        $created = 0;

        foreach ($items as $index => $item) {
            $productDetails = $this->resolveCatalogElementDetails($amoApi, $item);
            $productExternalId = (string) data_get($item, 'catalog_id', data_get($item, 'product_id', data_get($item, 'id', '')));
            $catalogName = $this->resolveCatalogName($amoApi, (string) data_get($item, 'metadata.catalog_id', data_get($item, 'catalog_id', '')));
            $category = trim((string) $catalogName);
            $productName = trim((string) data_get($productDetails, 'name', data_get($item, 'name', data_get($item, 'product_name', ''))));

            if ($productName === '') {
                $productName = ($catalogName !== null ? $catalogName : 'Услуга').' #'.($productExternalId !== '' ? $productExternalId : ($index + 1));
            }

            $quantity = (float) data_get($item, 'quantity', data_get($item, 'metadata.quantity', data_get($item, 'count', 1)));
            $unitPrice = (float) data_get($productDetails, 'price', 0);

            if ($unitPrice <= 0) {
                $unitPrice = (float) $this->catalogFieldValue($productDetails, ['BILL_PRICE', 'PRICE', 'PRICE_VALUE', 'AMOUNT', 'SUM'], 0);
            }

            if ($unitPrice <= 0) {
                $unitPrice = (float) data_get($item, 'price', data_get($item, 'unit_price', data_get($item, 'sale_price', 0)));
            }

            $totalAmount = (float) data_get($item, 'total', data_get($item, 'total_price', data_get($item, 'sum', $quantity * $unitPrice)));
            $sku = trim((string) $this->catalogFieldValue($productDetails, ['SKU', 'BILL_SKU', 'EXTERNAL_ID'], data_get($item, 'sku', data_get($item, 'external_id', ''))));

            EntityProduct::query()->create([
                'source_key' => $sourceKey,
                'external_id' => $productExternalId,
                'entity_type' => $entityType,
                'entity_external_id' => $entityExternalId,
                'entity_name' => $entityName,
                'category' => $category !== '' ? $category : null,
                'product_external_id' => $productExternalId !== '' ? $productExternalId : null,
                'product_name' => $productName,
                'product_sku' => $sku !== '' ? $sku : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'metadata' => [
                    'amo_entity' => $rawEntity,
                    'amo_catalog_element' => $item,
                    'amo_catalog_element_details' => $productDetails,
                ],
            ]);

            $created++;
        }

        return $created;
    }

    protected function resolveCatalogName(ApiClient $amoApi, string $catalogId): ?string
    {
        if ($catalogId === '') {
            return null;
        }

        if (array_key_exists($catalogId, $this->catalogListCache)) {
            return $this->catalogListCache[$catalogId];
        }

        try {
            $query = $amoApi->query('GET', '/api/v4/catalogs');
            $query->execute();
            $payload = $this->normalizeAmoEntity($query->response->validated());
            $catalogs = data_get($payload, '_embedded.catalogs', []);

            if (is_array($catalogs)) {
                foreach ($catalogs as $catalog) {
                    $id = (string) data_get($catalog, 'id', '');
                    $name = trim((string) data_get($catalog, 'name', ''));

                    if ($id !== '' && $name !== '') {
                        $this->catalogListCache[$id] = $name;
                    }
                }
            }
        } catch (Throwable) {
            $this->catalogListCache[$catalogId] = null;
        }

        return $this->catalogListCache[$catalogId] ?? null;
    }

    /**
     * @param  array<int, string>  $fieldCodes
     */
    protected function catalogFieldValue(array $payload, array $fieldCodes, mixed $default = null): mixed
    {
        $fields = data_get($payload, 'custom_fields_values', []);

        if (! is_array($fields)) {
            return $default;
        }

        foreach ($fields as $field) {
            $fieldCode = Str::upper(trim((string) data_get($field, 'field_code', '')));
            $fieldName = Str::upper(trim((string) data_get($field, 'field_name', '')));

            if (! in_array($fieldCode, $fieldCodes, true) && ! in_array($fieldName, $fieldCodes, true)) {
                continue;
            }

            $values = data_get($field, 'values', []);

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $candidate = data_get($value, 'value');

                if ($candidate !== null && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function resolveCatalogElementDetails(ApiClient $amoApi, array $item): array
    {
        $catalogId = (string) data_get($item, 'metadata.catalog_id', data_get($item, 'catalog_id', ''));
        $elementId = (string) data_get($item, 'id', '');

        if ($catalogId === '' || $elementId === '') {
            return [];
        }

        $cacheKey = $catalogId.'|'.$elementId;

        if (array_key_exists($cacheKey, $this->catalogElementCache)) {
            return $this->catalogElementCache[$cacheKey];
        }

        try {
            $query = $amoApi->query('GET', '/api/v4/catalogs/'.$catalogId.'/elements/'.$elementId);
            $query->execute();

            if (in_array($query->response->getCode(), [204, 404], true)) {
                return $this->catalogElementCache[$cacheKey] = [];
            }

            $payload = $this->normalizeAmoEntity($query->response->validated());

            return $this->catalogElementCache[$cacheKey] = $payload;
        } catch (Throwable) {
            return $this->catalogElementCache[$cacheKey] = [];
        }
    }

    protected function toDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
