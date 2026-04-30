<?php

namespace App\Services;

use App\Support\AccountContext;
use Illuminate\Support\Collection;

class CompanyResolver
{
    public function resolve(): AccountContext
    {
        return new AccountContext(
            name: (string) env('ACCOUNT_NAME', config('app.name', 'Owner Analytics')),
            timezone: (string) env('ACCOUNT_TIMEZONE', config('app.timezone', 'Europe/Kaliningrad')),
            settings: [
                'single_account' => true,
            ],
        );
    }

    /**
     * @return Collection<int, AccountContext>
     */
    public function all(): Collection
    {
        return collect([$this->resolve()]);
    }
}
