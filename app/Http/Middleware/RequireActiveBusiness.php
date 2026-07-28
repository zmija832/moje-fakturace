<?php

namespace App\Http\Middleware;

use App\Domain\BusinessContext\ActiveBusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveBusiness
{
    public function __construct(private readonly ActiveBusinessContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $this->context->business(),
            403,
            'Nejprve musí být zvolen aktivní fakturační subjekt.',
        );

        return $next($request);
    }
}
