<?php

namespace App\Services\Business;

use App\Enums\InvoiceStatus;
use App\Models\Business\Client;
use App\Models\Business\Invoice;
use Illuminate\Validation\ValidationException;

class InvoiceDuplicator
{
    public function __construct(
        private readonly InvoiceDraftService $drafts,
        private readonly InvoiceFormOptions $formOptions,
        private readonly InvoiceVatResolver $vatResolver,
    ) {}

    public function duplicate(Invoice $source): Invoice
    {
        if ($source->status !== InvoiceStatus::Issued || $source->issuedRevision === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Duplikovat lze pouze vystavenou fakturu.',
            ]);
        }

        $revision = $source->issuedRevision;
        $revision->loadMissing(['customerSnapshot', 'bankAccountSnapshot', 'items.vatSnapshot']);
        $client = Client::query()
            ->where('uuid', $revision->customerSnapshot->source_client_uuid)
            ->first();
        if ($client === null) {
            throw ValidationException::withMessages([
                'customer' => 'Původní odběratel již není dostupný. Vytvořte nový koncept ručně.',
            ]);
        }

        $defaults = $this->formOptions->defaults($client);
        $isVatPayer = $this->vatResolver->isVatPayer();
        $items = $revision->items->map(function ($item) use ($isVatPayer): array {
            $values = [
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type->value,
                'discount_value' => $item->discount_value,
            ];
            if ($isVatPayer) {
                $values['vat_rate_uuid'] = $item->vatSnapshot->source_vat_rate_uuid;
            }

            return $values;
        })->all();

        return $this->drafts->create([
            'customer_uuid' => $client->uuid,
            'bank_account_uuid' => $revision->bankAccountSnapshot?->source_bank_account_uuid,
            'currency' => $revision->currency,
            'issued_on' => $defaults['issued_on'],
            'taxable_supply_on' => $defaults['taxable_supply_on'],
            'due_on' => $defaults['due_on'],
            'payment_method' => $revision->payment_method->value,
            'variable_symbol' => null,
            'note' => $revision->note,
            'invoice_discount_type' => $revision->invoice_discount_type->value,
            'invoice_discount_value' => $revision->invoice_discount_value,
            'items' => $items,
        ]);
    }
}
