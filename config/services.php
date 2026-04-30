<?php

return [
    'amo' => [
        'base_url' => env('AMO_BASE_URL'),
        'access_token' => env('AMO_ACCESS_TOKEN'),
        'refresh_token' => env('AMO_REFRESH_TOKEN'),
        'client_id' => env('AMO_CLIENT_ID'),
        'client_secret' => env('AMO_CLIENT_SECRET'),
        'redirect_uri' => env('AMO_REDIRECT_URI'),
        'invoice_catalog_id' => env('AMO_INVOICE_CATALOG_ID', 3135),
        'invoice_status_field_id' => env('AMO_INVOICE_STATUS_FIELD_ID', 169931),
        'invoice_amount_field_id' => env('AMO_INVOICE_AMOUNT_FIELD_ID', 169893),
        'invoice_group_field_id' => env('AMO_INVOICE_GROUP_FIELD_ID', 431159),
        'invoice_vat_field_id' => env('AMO_INVOICE_VAT_FIELD_ID', 169889),
        'invoice_payment_hash_field_id' => env('AMO_INVOICE_PAYMENT_HASH_FIELD_ID', 458745),
        'invoice_items_field_id' => env('AMO_INVOICE_ITEMS_FIELD_ID', 169939),
    ],
    'weeek' => [
        'base_url' => env('WEEEK_BASE_URL'),
        'token' => env('WEEEK_TOKEN'),
    ],
];
