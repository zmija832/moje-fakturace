<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Models\Business\InvoicePublicLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class InvoicePublicViewTracker
{
    public function __construct(private readonly BusinessConnectionResolver $connectionResolver) {}

    public function record(InvoicePublicLink $link, ?CarbonImmutable $viewedAt = null): void
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        $viewedAt ??= CarbonImmutable::now();

        DB::connection($connection)->transaction(function () use ($link, $viewedAt): void {
            $locked = InvoicePublicLink::query()->whereKey($link->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $locked->forceFill([
                'first_viewed_at' => $locked->first_viewed_at ?? $viewedAt,
                'last_viewed_at' => $viewedAt,
            ])->save();
        }, 3);
    }
}
