<?php

namespace App\Http\Controllers;

use App\Services\Analytics\ClientAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request, ClientAnalyticsService $service): View
    {
        $period = AnalyticsPeriod::fromRequest($request);

        return view('clients.index', [
            'period' => $period,
            'data' => $service->build($period),
        ]);
    }
}
