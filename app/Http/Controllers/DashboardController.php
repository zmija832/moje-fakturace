<?php

namespace App\Http\Controllers;

use App\Domain\BusinessContext\ActiveBusinessContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ActiveBusinessContext $context): View
    {
        return view('dashboard', [
            'user' => $request->user(),
            'activeBusiness' => $context->business(),
        ]);
    }
}
