<?php

namespace App\Http\Middleware;

use App\Domain\BusinessContext\BusinessSwitcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveBusiness
{
    public function __construct(private readonly BusinessSwitcher $switcher) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $this->switcher->resolve($request->user(), $request->session());
        }

        return $next($request);
    }
}
