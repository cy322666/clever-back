<?php

namespace App\Console\Commands;

use App\Models\SourceConnection;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use Illuminate\Console\Command;

class SyncamoCRM extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-amocrm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Exception
     */
    public function handle()
    {
        $connection = SourceConnection::query()
            ->where('is_enabled', true)
            ->where('source_key', 'amocrm')
            ->first();

        (new AmoCrmConnector)->sync($connection);
    }
}
