<?php

namespace App\Http\Middleware;

use App\Services\CompanyResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetAnalyticsContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyResolver = app(CompanyResolver::class);
        $account = $companyResolver->resolve();
        View::share('currentAccount', $account);
        date_default_timezone_set($account->timezone ?? config('app.timezone'));

        View::share('analyticsNav', [
            ['label' => 'Главная', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['label' => 'Продажи', 'route' => 'sales', 'icon' => 'sales'],
            ['label' => 'Услуги', 'route' => 'products', 'icon' => 'products'],
            ['label' => 'Счета', 'route' => 'invoices', 'icon' => 'finance'],
            ['label' => 'Компании', 'route' => 'clients', 'icon' => 'clients'],
            ['label' => 'Производство', 'route' => 'production', 'icon' => 'production'],
            ['label' => 'Финансы', 'route' => 'finance', 'icon' => 'finance'],
            ['label' => 'Риски', 'route' => 'risks', 'icon' => 'risks'],
            ['label' => 'Настройки', 'route' => 'integrations', 'icon' => 'integrations'],
            ['label' => 'Справочники', 'route' => 'manual-adjustments', 'icon' => 'manual'],
        ]);

        return $next($request);
    }
}
