<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePublicLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCancellationService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function cancel(string $invoiceUuid, int $expectedVersion, string $correlationUuid, string $reason): Invoice
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            return DB::connection($connection)->transaction(function () use ($connection, $invoiceUuid, $expectedVersion, $correlationUuid, $reason): Invoice {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
                if ($invoice->status === InvoiceStatus::Cancelled
                    && hash_equals((string) $invoice->cancellation_correlation_uuid, $correlationUuid)) {
                    return $invoice;
                }
                if ($invoice->status !== InvoiceStatus::Issued || $invoice->archived_at !== null) {
                    throw ValidationException::withMessages(['invoice' => 'Stornovat lze pouze aktivní vystavenou fakturu.']);
                }
                if ($invoice->version !== $expectedVersion) {
                    throw ValidationException::withMessages(['version' => 'Faktura se mezitím změnila. Načtěte její aktuální stav.']);
                }
                if ($invoice->payments()->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Fakturu s platební historií nelze stornovat. Nejprve bezpečně vyřešte platby.']);
                }

                $actor = $this->actor();
                InvoicePublicLink::query()->active()->where('invoice_id', $invoice->id)->lockForUpdate()->get()
                    ->each(function (InvoicePublicLink $link) use ($actor, $invoice): void {
                        $link->forceFill(['revoked_at' => now(), 'revoked_by_actor' => $actor])->save();
                        $link->setRelation('invoice', $invoice);
                        $this->auditWriter->write(
                            BusinessAuditEvent::InvoicePublicLinkRevoked,
                            BusinessAuditableType::InvoicePublicLink,
                            $link->uuid,
                            null,
                            $this->auditSanitizer->snapshot(BusinessAuditableType::InvoicePublicLink, $link),
                            ['revoked_at'],
                            BusinessAuditableType::Invoice,
                            $invoice->uuid,
                            ['reason' => 'invoice_cancelled'],
                        );
                    });

                $before = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
                DB::connection($connection)->statement(
                    "SET @invoice_admin_operation = 'cancel', @invoice_admin_uuid = ?, @invoice_admin_correlation = ?",
                    [$invoice->uuid, $correlationUuid],
                );
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)->update([
                    'status' => InvoiceStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancelled_by_actor' => $actor,
                    'cancellation_reason' => $reason,
                    'cancellation_correlation_uuid' => $correlationUuid,
                    'updated_at' => now(),
                ]);
                $cancelled = Invoice::query()->whereKey($invoice->id)->firstOrFail();
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceCancelled,
                    BusinessAuditableType::Invoice,
                    $cancelled->uuid,
                    $before,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $cancelled),
                    ['status', 'cancelled_at', 'cancelled_by_actor', 'cancellation_reason'],
                );

                return $cancelled;
            }, 3);
        } finally {
            DB::connection($connection)->statement(
                'SET @invoice_admin_operation = NULL, @invoice_admin_uuid = NULL, @invoice_admin_correlation = NULL',
            );
        }
    }

    private function actor(): string
    {
        $user = auth()->user();

        return $user ? 'central-user:'.$user->getAuthIdentifier() : 'system';
    }
}
