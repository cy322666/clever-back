<?php

namespace App\Services\Integrations\Contracts;

use App\Models\SourceConnection;
use App\Services\Integrations\SyncResult;

interface SourceConnector
{
    public function supports(string $driver): bool;

    public function sync(SourceConnection $connection): SyncResult;
}
