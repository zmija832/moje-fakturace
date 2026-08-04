<?php

namespace App\Http\Controllers;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Services\Business\InvoicePaymentReader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ActiveBusinessContext $context, InvoicePaymentReader $payments): View
    {
        return view('dashboard', [
            'user' => $request->user(),
            'activeBusiness' => $context->business(),
            'paymentOverview' => $context->business() === null ? collect() : $payments->dashboard(),
        ]);
    }
}
