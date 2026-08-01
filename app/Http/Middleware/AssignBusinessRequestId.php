<?php

namespace App\Http\Middleware;

use App\Domain\Audit\BusinessAuditRequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignBusinessRequestId
{
    public function __construct(private readonly BusinessAuditRequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $this->context->setRequestId($requestId);
        $request->attributes->set('business_request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
