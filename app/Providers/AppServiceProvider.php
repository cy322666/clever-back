<?php

namespace App\Providers;

use App\Services\Auth\OwnerBootstrapper;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        app(OwnerBootstrapper::class)->ensureOwnerUser();
    }
}
