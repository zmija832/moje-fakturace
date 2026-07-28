<?php

namespace App\Http\Controllers;

use App\Domain\BusinessContext\BusinessSwitcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BusinessSwitchController extends Controller
{
    public function __invoke(
        Request $request,
        string $businessUuid,
        BusinessSwitcher $switcher,
    ): RedirectResponse {
        $switcher->switch($request->user(), $businessUuid, $request);

        return back()->with('status', 'Aktivní fakturační subjekt byl změněn.');
    }
}
