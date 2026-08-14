<?php

namespace App\Http\Middleware;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicInvoiceLink
{
    public function __construct(private readonly ActiveBusinessContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1, 404);
        $tokenHash = hash('sha256', $token);
        $businesses = Business::query()->where('is_active', true)
            ->whereIn('connection_name', config('business.allowed_connections', []))
            ->get();
        $matches = [];

        foreach ($businesses as $business) {
            $connection = BusinessConnection::fromConfiguredValue($business->connection_name)->connectionName();
            try {
                $link = DB::connection($connection)->table('invoice_public_links')
                    ->join('invoices', 'invoices.id', '=', 'invoice_public_links.invoice_id')
                    ->where('invoice_public_links.token_hash', $tokenHash)
                    ->whereNull('invoice_public_links.revoked_at')
                    ->where('invoices.status', 'issued')
                    ->select('invoice_public_links.id')
                    ->first();
            } catch (QueryException $exception) {
                Log::warning('Tenant databáze není připravena pro Webfakturu.', [
                    'connection' => $connection,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }
            if ($link !== null) {
                $matches[] = [$business, (int) $link->id];
            }
        }

        abort_unless(count($matches) === 1, 404);
        [$business, $linkId] = $matches[0];
        $this->context->set($business);
        $request->attributes->set('public_invoice_link_id', $linkId);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
