<?php

namespace App\Http\Controllers;

use App\Services\Analytics\RiskAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RiskController extends Controller
{
    public function index(Request $request, RiskAnalyticsService $service): View
    {
        $period = AnalyticsPeriod::fromRequest($request);

        return view('risks.index', [
            'period' => $period,
            'data' => $service->build($period),
        ]);
    }
}
