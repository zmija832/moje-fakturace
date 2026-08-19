<?php

namespace App\Http\Controllers;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Services\Business\DashboardOverviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ActiveBusinessContext $context, DashboardOverviewService $dashboard): View
    {
        return view('dashboard', [
            'user' => $request->user(),
            'activeBusiness' => $context->business(),
            'overview' => $context->business() === null ? null : $dashboard->overview(),
        ]);
    }
}
