<?php

namespace App\Console\Commands;

use App\Models\SourceConnection;
use App\Services\Integrations\Connectors\AmoCrmConnector;
use App\Services\Integrations\Connectors\WeeekConnector;
use Illuminate\Console\Command;

class SyncWeeek extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-weeek';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connection = SourceConnection::query()
            ->where('is_enabled', true)
            ->where('source_key', 'weeek')
            ->first();

        (new WeeekConnector())->sync($connection);
    }
}
