<?php

namespace App\Services\Analytics;

use App\Models\Buyer;
use App\Models\EntityProduct;
use App\Models\SalesLead;
use App\Models\SourceConnection;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $rows = $this->productRows($period);
        $productRows = $rows
            ->groupBy(fn (array $row) => $this->productGroupKey($row))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $totalQuantity = (float) $group->sum(fn (array $row): float => (float) ($row['quantity'] ?? 0));
                $totalAmount = (float) $group->sum(fn (array $row): float => (float) ($row['total_amount'] ?? 0));
                $weightedUnitPrice = $totalQuantity > 0 ? $totalAmount / $totalQuantity : (float) ($first['unit_price'] ?? 0);

                return [
                    'id' => 'product-'.$this->productGroupKey($first),
                    '__key' => 'product-'.$this->productGroupKey($first),
                    'product_external_id' => (string) ($first['product_external_id'] ?? ''),
                    'product_name' => (string) ($first['product_name'] ?? ''),
                    'category' => (string) ($first['category'] ?? ''),
                    'product_sku' => $first['product_sku'] ?? null,
                    'quantity' => round($totalQuantity, 1),
                    'unit_price' => round($weightedUnitPrice, 2),
                    'total_amount' => round($totalAmount, 0),
                    'entities_count' => $group->pluck('entity_external_id')->filter()->unique()->count(),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();

        $dealRows = $rows->whereIn('entity_type', ['deal', 'lead']);
        $customerRows = $rows->where('entity_type', 'customer');
        $dealEntities = $dealRows->pluck('entity_external_id')->filter()->unique()->count();
        $customerEntities = $customerRows->pluck('entity_external_id')->filter()->unique()->count();
        $rowsCount = $productRows->count();
        $totalAmount = (float) $productRows->sum(fn (array $row): float => (float) ($row['total_amount'] ?? 0));
        $dealAmount = (float) $dealRows->sum(fn (array $row): float => (float) ($row['total_amount'] ?? 0));
        $customerAmount = (float) $customerRows->sum(fn (array $row): float => (float) ($row['total_amount'] ?? 0));
        $avgRowAmount = $rowsCount > 0 ? $totalAmount / $rowsCount : 0;
        $avgQuantity = $rowsCount > 0 ? (float) $productRows->sum(fn (array $row): float => (float) ($row['quantity'] ?? 0)) / $rowsCount : 0;

        $typeBreakdown = $rows
            ->groupBy('entity_type')
            ->map(fn (Collection $group, string $entityType) => [
                'entity_type' => $entityType,
                'rows_count' => $group->count(),
                'total_amount' => $group->sum(fn (array $row): float => (float) ($row['total_amount'] ?? 0)),
            ])
            ->sortByDesc('total_amount')
            ->values();

        return [
            'kpis' => [
                [
                    'label' => 'Услуг',
                    'value' => number_format($rowsCount),
                    'hint' => 'Уникальные услуги',
                    'tone' => 'brand',
                ],
                [
                    'label' => 'Сумма услуг',
                    'value' => number_format($totalAmount, 0, ',', ' '),
                    'hint' => 'Сделки + клиенты',
                    'tone' => 'emerald',
                ],
                [
                    'label' => 'Сделок с услугами',
                    'value' => number_format($dealEntities),
                    'hint' => 'Уникальные сделки',
                    'tone' => 'cyan',
                ],
                [
                    'label' => 'Клиентов с услугами',
                    'value' => number_format($customerEntities),
                    'hint' => 'Уникальные клиенты',
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Средняя сумма позиции',
                    'value' => number_format($avgRowAmount, 0, ',', ' '),
                    'hint' => 'Средний чек позиции',
                    'tone' => 'slate',
                ],
                [
                    'label' => 'Среднее количество услуг',
                    'value' => number_format($avgQuantity, 1, ',', ' '),
                    'hint' => 'На строку услуги',
                    'tone' => 'rose',
                ],
            ],
            'rows' => $productRows->all(),
            'type_breakdown' => $typeBreakdown,
            'deal_amount' => $dealAmount,
            'customer_amount' => $customerAmount,
            'period' => $period,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function productRows(AnalyticsPeriod $period): Collection
    {
        $periodColumn = Schema::hasColumn('entity_products', 'entity_date')
            ? "coalesce(entity_products.entity_date, entity_products.created_at)"
            : 'entity_products.created_at';

        $soldLeadStatusColumn = Schema::hasColumn('sales_leads', 'status_id') ? 'status_id' : 'status';

        $soldLeadNames = SalesLead::query()
            ->where($soldLeadStatusColumn, 142)
            ->pluck('name', 'external_id')
            ->all();

        $soldBuyerNames = Buyer::query()
            ->whereRaw('coalesce(purchases_count, 0) > 0')
            ->pluck('name', 'external_id')
            ->all();

        $soldLeadIds = SalesLead::query()
            ->where($soldLeadStatusColumn, 142)
            ->pluck('external_id')
            ->map(fn ($value): string => (string) $value)
            ->all();

        $soldBuyerIds = Buyer::query()
            ->whereRaw('coalesce(purchases_count, 0) > 0')
            ->pluck('external_id')
            ->map(fn ($value): string => (string) $value)
            ->all();

        $defaultBaseUrl = SourceConnection::query()
            ->whereIn('source_key', ['amo', 'amocrm'])
            ->select('settings')
            ->get()
            ->map(fn (SourceConnection $connection): ?string => $this->amoBaseUrl($connection->settings))
            ->filter()
            ->first()
            ?? SourceConnection::query()
                ->select('settings')
                ->get()
                ->map(fn (SourceConnection $connection): ?string => $this->amoBaseUrl($connection->settings))
                ->filter()
                ->first();

        $rows = EntityProduct::query()
            ->when($period->key !== 'all', function ($query) use ($period, $periodColumn) {
                $query->whereBetween(DB::raw($periodColumn), [
                    $period->from->toDateTimeString(),
                    $period->to->toDateTimeString(),
                ]);
            })
            ->orderByDesc('total_amount')
            ->orderByDesc('id')
            ->get()
            ->map(function (EntityProduct $product) use ($soldLeadNames, $soldBuyerNames, $soldLeadIds, $soldBuyerIds, $defaultBaseUrl): ?array {
                $entityExternalId = (string) $product->entity_external_id;
                $entityName = trim((string) $product->entity_name);

                if (! $this->isSoldProductEntity((string) $product->entity_type, $entityExternalId, $soldLeadIds, $soldBuyerIds)) {
                    return null;
                }

                if ($entityName === '') {
                    $entityName = match ((string) $product->entity_type) {
                        'lead', 'deal' => trim((string) ($soldLeadNames[$entityExternalId] ?? '')),
                        'customer' => trim((string) ($soldBuyerNames[$entityExternalId] ?? '')),
                        default => '',
                    };
                }

                if ($entityName === '') {
                    $entityName = match ((string) $product->entity_type) {
                        'customer' => 'Клиент #'.$entityExternalId,
                        default => 'Сделка #'.$entityExternalId,
                    };
                }

                $productExternalId = trim((string) $product->product_external_id);
                $productName = trim((string) $product->product_name);

                if ($productName === '') {
                    $productName = $productExternalId !== '' ? 'Услуга #'.$productExternalId : 'Услуга #'.$product->id;
                }

                return [
                    'id' => (string) $product->id,
                    'entity_type' => (string) $product->entity_type,
                    'entity_external_id' => $entityExternalId,
                    'entity_name' => $entityName,
                    'category' => (string) ($product->category ?? ''),
                    'entity_url' => $this->amoEntityUrl($defaultBaseUrl, (string) $product->entity_type, $entityExternalId),
                    'entity_date' => optional($product->entity_date)?->toDateTimeString(),
                    'product_external_id' => $productExternalId !== '' ? $productExternalId : null,
                    'product_name' => $productName,
                    'product_sku' => $product->product_sku,
                    'quantity' => (float) $product->quantity,
                    'unit_price' => (float) $product->unit_price,
                    'total_amount' => (float) $product->total_amount,
                ];
            })
            ->filter()
            ->values();

        if ($rows->isNotEmpty()) {
            return $rows;
        }

        $fallbackRows = collect();

        foreach (SalesLead::query()->where($soldLeadStatusColumn, 142)->select('id', 'external_id', 'name', 'lead_created_at', 'metadata')->get() as $lead) {
            $fallbackRows = $fallbackRows->merge(
                $this->catalogRowsFromPayload(
                    'deal',
                    (string) $lead->external_id,
                    (string) $lead->name,
                    $lead->lead_created_at,
                    data_get($lead->metadata, 'amo_lead', []),
                    $defaultBaseUrl
                )
            );
        }

        foreach (Buyer::query()->whereRaw('coalesce(purchases_count, 0) > 0')->select('id', 'external_id', 'name', 'created_at', 'metadata')->get() as $buyer) {
            $fallbackRows = $fallbackRows->merge(
                $this->catalogRowsFromPayload(
                    'customer',
                    (string) $buyer->external_id,
                    (string) $buyer->name,
                    $buyer->created_at,
                    data_get($buyer->metadata, 'amo_customer', []),
                    $defaultBaseUrl
                )
            );
        }

        return $fallbackRows
            ->when($period->key !== 'all', function (Collection $collection) use ($period): Collection {
                return $collection->filter(function (array $row) use ($period): bool {
                    $entityDate = $row['entity_date'] ?? null;

                    if ($entityDate === null || $entityDate === '') {
                        return false;
                    }

                    try {
                        $date = Carbon::parse((string) $entityDate);
                    } catch (\Throwable) {
                        return false;
                    }

                    return $date->betweenIncluded($period->from, $period->to);
                });
            })
            ->sortByDesc('total_amount')
            ->values();
    }

    protected function productGroupKey(array $row): string
    {
        $productExternalId = trim((string) ($row['product_external_id'] ?? ''));
        $productName = trim((string) ($row['product_name'] ?? ''));

        return $productExternalId !== '' ? $productExternalId : ($productName !== '' ? mb_strtolower($productName) : (string) ($row['id'] ?? 'unknown'));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function catalogRowsFromPayload(
        string $entityType,
        string $entityExternalId,
        string $entityName,
        mixed $entityDate,
        mixed $payload,
        ?string $baseUrl,
    ): Collection {
        $items = data_get($payload, 'catalog_elements', data_get($payload, '_embedded.catalog_elements', []));

        if (! is_array($items) || $items === []) {
            return collect();
        }

        return collect($items)->values()->map(function (mixed $item, int $index) use ($entityType, $entityExternalId, $entityName, $entityDate, $baseUrl): array {
            $item = is_array($item) ? $item : (is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : get_object_vars($item));

            $productExternalId = (string) data_get($item, 'catalog_id', data_get($item, 'product_id', data_get($item, 'id', '')));
            $productName = trim((string) data_get($item, 'name', data_get($item, 'product_name', '')));

            if ($productName === '') {
                $productName = 'Услуга #'.($productExternalId !== '' ? $productExternalId : ($index + 1));
            }

            $quantity = (float) data_get($item, 'quantity', data_get($item, 'count', 1));
            $unitPrice = (float) data_get($item, 'price', data_get($item, 'unit_price', data_get($item, 'sale_price', 0)));
            $totalAmount = (float) data_get($item, 'total', data_get($item, 'total_price', data_get($item, 'sum', $quantity * $unitPrice)));

                return [
                    'id' => 'fallback-'.$entityType.'-'.$entityExternalId.'-'.($productExternalId !== '' ? $productExternalId : $index),
                    'entity_type' => $entityType,
                    'entity_external_id' => $entityExternalId,
                    'entity_name' => $entityName,
                    'category' => trim((string) data_get($item, 'category', data_get($item, 'catalog_name', ''))) ?: null,
                    'entity_url' => $this->amoEntityUrl($baseUrl, $entityType, $entityExternalId),
                    'entity_date' => $entityDate instanceof Carbon ? $entityDate->toDateTimeString() : (method_exists($entityDate, 'toDateTimeString') ? $entityDate->toDateTimeString() : null),
                'product_external_id' => $productExternalId !== '' ? $productExternalId : null,
                'product_name' => $productName,
                'product_sku' => trim((string) data_get($item, 'sku', data_get($item, 'external_id', ''))) ?: null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
            ];
        });
    }

    protected function amoBaseUrl(mixed $settings): ?string
    {
        $baseUrl = trim((string) data_get(is_array($settings) ? $settings : [], 'base_url', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : null;
    }

    protected function amoEntityUrl(?string $baseUrl, string $entityType, string $entityExternalId): ?string
    {
        if ($baseUrl === null || $entityExternalId === '') {
            return null;
        }

        $path = match ($entityType) {
            'customer' => '/customers/detail/'.$entityExternalId,
            'lead', 'deal' => '/leads/detail/'.$entityExternalId,
            default => '/leads/detail/'.$entityExternalId,
        };

        return $baseUrl.$path;
    }

    protected function isSoldProductEntity(string $entityType, string $entityExternalId, array $soldLeadIds, array $soldBuyerIds): bool
    {
        return match ($entityType) {
            'lead', 'deal' => in_array($entityExternalId, $soldLeadIds, true),
            'customer' => in_array($entityExternalId, $soldBuyerIds, true),
            default => false,
        };
    }
}
