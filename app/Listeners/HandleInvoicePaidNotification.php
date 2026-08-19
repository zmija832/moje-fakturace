<?php

namespace App\Listeners;

use App\Events\InvoicePaymentChanged;
use App\Services\Business\InvoicePaidNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleInvoicePaidNotification
{
    public function __construct(private readonly InvoicePaidNotificationService $service) {}

    public function handle(InvoicePaymentChanged $event): void
    {
        try {
            $this->service->handle($event->snapshot);
        } catch (Throwable $e) {
            Log::error('Zpracování notifikace o úhradě selhalo.', ['invoice_uuid' => $event->snapshot->invoiceUuid, 'exception_class' => $e::class]);
        }
    }
}
