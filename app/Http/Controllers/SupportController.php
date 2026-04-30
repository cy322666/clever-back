<?php

namespace App\Http\Controllers;

use App\Services\Analytics\SupportAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request, SupportAnalyticsService $service): View
    {
        $period = AnalyticsPeriod::fromRequest($request);

        return view('support.index', [
            'period' => $period,
            'data' => $service->build($period),
        ]);
    }
}
