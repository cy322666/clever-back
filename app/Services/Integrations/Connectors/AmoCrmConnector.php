<?php

namespace App\Services\Integrations\Connectors;

use App\Models\Buyer;
use App\Models\Client;
use App\Models\EntityProduct;
use App\Models\Invoice;
use App\Models\Pipeline;
use App\Models\SalesLead;
use App\Models\SourceConnection;
use App\Services\Integrations\Contracts\SourceConnector;
use App\Services\Integrations\SyncResult;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Ufee\AmoV4\ApiClient;

/**
 * Коннектор amoCRM для синхронизации сделок, покупателей и товарных позиций.
 */
class AmoCrmConnector
{
    protected array $catalogElementCache = [];
    protected array $catalogListCache = [];

    public function supports(string $driver): bool
    {
        return in_array($driver, ['amo', 'amocrm'], true);
    }

    public function getProduct(string|int $productId): ?array
    {
        $response = Http::withToken(config('services.amo.access_token'))
            ->acceptJson()
            ->timeout(15)
            ->get("https://blacklever.amocrm.ru/api/v4/catalogs/5655/elements/".$productId);

        $response->throw();

        return $response->json();
    }

    /**
     * @throws \Exception
     */
    public function sync(SourceConnection $connection)
    {
        $settings = $this->resolveSettings($connection);

        if (! $this->isConfigured($settings)) {
            return SyncResult::fail('amoCRM is not configured');
        }

        $amoApi = $this->buildAmoClient($settings);

        if (! $amoApi) {
            return SyncResult::fail('Unable to build amoCRM client');
        }

        $pipelineIds = Pipeline::query()
            ->pluck('external_id')
            ->filter()
            ->map(fn ($value): string => (string) $value)
            ->values()
            ->all();

        foreach ($pipelineIds as $pipelineId) {

            $leadQuery = $amoApi->leads()->filter([
                'pipeline_id' => $pipelineId,
                'created_at' => [
                    'from' => CarbonImmutable::now()->startOfYear()->startOfDay()->timestamp,
                    'to'   => CarbonImmutable::now()->endOfDay()->timestamp,
                ]
            ], ['catalog_elements', 'companies']);

            $leads = $leadQuery->fetchAll();

            foreach ($leads as $lead) {
                $client = null;

                if (count($lead->companies) > 0) {
                    $client = $this->upsertAmoCompanyClient($lead->companies[0], $lead->name);
                }

                SalesLead::query()->updateOrCreate(
                    [
                        'external_id' => $lead->id,
                    ],
                    [
                        'client_id' => $client?->id,
                        'name' => $lead->name,
                        'source_channel' => $lead->cf('Источник')->getValue(),
                        'status_id' => $lead->status_id,
                        'budget_amount' => $lead->price,
                        'lead_created_at' => Carbon::parse($lead->created_at)->toDateTimeString(),
                        'lead_closed_at' => $lead->closed_at ? Carbon::parse($lead->closed_at)->toDateTimeString() : null,
                        'last_activity_at' => Carbon::parse($lead->updated_at)->toDateTimeString(),
                        'pipeline_id' => $lead->pipeline_id,
                        'metadata' => [
                            'amo_lead' => $lead->toArray(),
                        ],
                    ]
                );

                if (!empty($lead->catalog_elements[0])) {

                    EntityProduct::query()->updateOrCreate([
                        'external_id' => $lead->catalog_elements[0]->id,
                        'entity_external_id' => $lead->id,
                        'entity_type' => 'lead',
                    ], [
                        'quantity' => $lead->catalog_elements[0]->metadata->quantity,
                        ]
                    );
                }
            }
        }

        $customers = $amoApi->customers()
            ->get(['catalog_elements', 'companies']);

        foreach ($customers as $customer) {
            $client = null;

            if (count($customer->companies) > 0) {
                $client = $this->upsertAmoCompanyClient($customer->companies[0], $customer->name);
            }

            Buyer::query()->updateOrCreate([
                'external_id' => $customer->id,
            ], [
                'client_id' => $client?->id,
                'name' => $customer->name,
                'status' => $customer->status_id,
                'periodicity' => $customer->periodicity,
                'purchases_count' => (float) ($customer->purchases_count ?? 0),
                'average_check' => (float) ($customer->average_check ?? 0),
                'ltv' => (float) ($customer->ltv ?? 0),
                'next_price' => (float) ($customer->next_price ?? 0),
                'next_date' => filled($customer->next_date) ? Carbon::parse($customer->next_date)->toDateTimeString() : null,
                'metadata' => [
                    'amo_customer' => $customer->toArray(),
                ],
            ]);

            if (!empty($customer->catalog_elements[0])) {

                EntityProduct::query()->updateOrCreate([
                    'external_id' => $customer->catalog_elements[0]->id,
                    'entity_external_id' => $customer->id,
                    'entity_type' => 'customer',
                ], [
                    'quantity' => $customer->catalog_elements[0]->metadata->quantity,
                    ]
                );
            }
        }

        $companies = Client::query()
            ->where('source_type', 'amo_company')
            ->whereNotNull('external_id')
            ->get();

        foreach ($companies as $companyModel) {

            $company = $amoApi->companies()->find($companyModel->external_id);

            $companyModel->name = $company->name;
            $companyModel->metadata = array_merge($companyModel->metadata ?? [], [
                'amo_company' => method_exists($company, 'toArray') ? $company->toArray() : [],
            ]);
            $companyModel->save();
        }

        $products = EntityProduct::query()->get();

        foreach ($products as $productModel) {

            $product = $this->getProduct($productModel->external_id);

            try {
                $productModel->forceFill([
                    'product_name' => $product['name'] ?? $productModel->product_name,
                    'total_amount' => $this->getAmoFieldValueByName($product, 'Цена', $productModel->total_amount),
                    'category' => $this->getAmoFieldValueByName($product, 'Группа', $productModel->category),
                    'metadata' => $product,
                ]);

                $productModel->save();
            } catch (\Throwable $e) {
                report($e);

            }
        }

        $invoiceStats = $this->syncInvoices($amoApi, $connection, $settings);

        return SyncResult::ok(
            $invoiceStats['pulled'] ?? 0,
            $invoiceStats['created'] ?? 0,
            $invoiceStats['updated'] ?? 0,
            ['invoices' => $invoiceStats]
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

    protected function upsertAmoCompanyClient(object $company, ?string $fallbackName = null): Client
    {
        $externalId = (string) data_get($company, 'id', '');
        $name = trim((string) data_get($company, 'name', $fallbackName ?: 'amoCRM company '.$externalId));

        return Client::query()->updateOrCreate(
            [
                'source_type' => 'amo_company',
                'external_id' => $externalId,
            ],
            [
                'name' => $name !== '' ? $name : 'amoCRM company '.$externalId,
                'category' => 'company',
                'status' => 'active',
                'metadata' => [
                    'amo_company' => method_exists($company, 'toArray') ? $company->toArray() : [],
                ],
            ],
        );
    }

    protected function filledSettings(array $settings): array
    {
        return array_filter($settings, fn ($value): bool => filled($value));
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

    protected function leadSourceChannel(array $leadData): ?string
    {
        $fields = data_get($leadData, 'custom_fields_values', data_get($leadData, '_embedded.custom_fields_values', []));

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if ((string) data_get($field, 'field_id') !== '431237') {
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
                $productName = ($catalogName !== null ? $catalogName : 'Товар').' #'.($productExternalId !== '' ? $productExternalId : ($index + 1));
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
