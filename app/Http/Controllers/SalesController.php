<?php

namespace App\Http\Controllers;

use App\Services\Analytics\SalesAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(Request $request, SalesAnalyticsService $service): View
    {
        $period = AnalyticsPeriod::fromRequest($request);

        return view('sales.index', [
            'period' => $period,
            'data' => $service->build($period),
        ]);
    }
}
