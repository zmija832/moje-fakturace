<?php

namespace App\Events;

use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class InvoicePaymentChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly InvoicePaymentEventSnapshot $snapshot) {}
}
