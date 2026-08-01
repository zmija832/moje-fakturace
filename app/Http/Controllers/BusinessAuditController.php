<?php

namespace App\Http\Controllers;

use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Http\Requests\BusinessAuditIndexRequest;
use App\Models\Business\AuditLog;
use App\Services\Business\BusinessAuditService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BusinessAuditController extends Controller
{
    public function index(BusinessAuditIndexRequest $request, BusinessAuditService $service): View
    {
        $filters = $request->validated();

        return view('business.audit.index', [
            'audits' => $service->search($filters),
            'filters' => $filters,
            'events' => BusinessAuditEvent::options(),
            'auditableTypes' => BusinessAuditableType::options(),
        ]);
    }

    public function show(string $uuid, BusinessAuditService $service): View
    {
        Gate::authorize('view', AuditLog::class);

        return view('business.audit.show', ['audit' => $service->find($uuid)]);
    }
}
