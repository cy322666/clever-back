<?php

namespace App\Http\Controllers;

use App\Services\Analytics\ProductionAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionController extends Controller
{
    public function index(Request $request, ProductionAnalyticsService $service): View
    {
        $period = AnalyticsPeriod::fromRequest($request);

        return view('production.index', [
            'period' => $period,
            'data' => $service->build($period),
        ]);
    }
}
