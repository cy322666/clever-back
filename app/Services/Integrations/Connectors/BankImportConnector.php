<?php

namespace App\Services\Integrations\Connectors;

use App\Models\SourceConnection;
use App\Services\Integrations\Contracts\SourceConnector;
use App\Services\Integrations\SyncResult;

class BankImportConnector implements SourceConnector
{
    public function supports(string $driver): bool
    {
        return in_array($driver, ['bank-import', 'bank', 'tochka'], true);
    }

    public function sync(SourceConnection $connection): SyncResult
    {
        return SyncResult::ok(
            payload: [
                'source' => 'Точка',
                'mode' => 'manual-import',
            ],
            message: 'Точка is import-only. Upload bank statements from /imports/bank.'
        );
    }
}
