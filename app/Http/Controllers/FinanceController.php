<?php

namespace App\Http\Controllers;

use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request, FinanceAnalyticsService $service): View
    {
        $period = AnalyticsPeriod::fromRequest($request);

        return view('finance.index', [
            'period' => $period,
            'data' => $service->build($period),
        ]);
    }
}
