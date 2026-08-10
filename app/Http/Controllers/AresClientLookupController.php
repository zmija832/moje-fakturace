<?php

namespace App\Http\Controllers;

use App\Http\Requests\AresClientLookupRequest;
use App\Services\External\Ares\AresClientLookupService;
use App\Services\External\Ares\AresLookupException;
use Illuminate\Http\JsonResponse;

class AresClientLookupController extends Controller
{
    public function __invoke(
        AresClientLookupRequest $request,
        AresClientLookupService $service,
    ): JsonResponse {
        try {
            return response()->json($service->findByIco($request->validated('ico')));
        } catch (AresLookupException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->notFound ? 404 : 503);
        }
    }
}
