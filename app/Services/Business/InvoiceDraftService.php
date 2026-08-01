<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceBankAccountSnapshot;
use App\Models\Business\InvoiceCustomerSnapshot;
use App\Models\Business\InvoiceItem;
use App\Models\Business\InvoiceSupplierSnapshot;
use App\Models\Business\InvoiceVatSnapshot;
use App\Models\Business\VatRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceDraftService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly VatRateService $vatRateService,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Invoice
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($attributes): Invoice {
            $supplier = CompanySetting::query()
                ->where('singleton_key', CompanySetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            if ($supplier === null) {
                throw ValidationException::withMessages([
                    'supplier' => 'Před vytvořením faktury dokončete nastavení fakturačního subjektu.',
                ]);
            }

            $customer = Client::query()
                ->where('uuid', (string) $attributes['customer_uuid'])
                ->whereNull('archived_at')
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $bankAccount = $this->bankAccount($attributes['bank_account_uuid'] ?? null, (string) $attributes['currency']);
            $taxDate = CarbonImmutable::parse((string) $attributes['taxable_supply_on']);
            $vatRates = $this->vatRates((array) $attributes['items'], $taxDate, (bool) $supplier->is_vat_payer);

            $invoice = new Invoice;
            $invoice->fill([
                'currency' => $attributes['currency'],
                'issued_on' => $attributes['issued_on'],
                'taxable_supply_on' => $attributes['taxable_supply_on'],
                'due_on' => $attributes['due_on'],
                'payment_method' => $attributes['payment_method'],
                'variable_symbol' => $attributes['variable_symbol'] ?? null,
                'note' => $attributes['note'] ?? null,
            ]);
            $invoice->forceFill([
                'document_type' => DocumentType::IssuedInvoice->value,
                'status' => InvoiceStatus::Draft->value,
            ])->save();

            $this->storeSupplierSnapshot($invoice, $supplier);
            $this->storeCustomerSnapshot($invoice, $customer);

            if ($bankAccount !== null) {
                $this->storeBankSnapshot($invoice, $bankAccount);
            }

            $vatSnapshots = $this->storeVatSnapshots($invoice, $vatRates);
            $this->storeItems($invoice, (array) $attributes['items'], $vatSnapshots);
            $invoice->load(['supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'vatSnapshots', 'items.vatSnapshot']);
            $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
            $this->auditWriter->write(
                BusinessAuditEvent::InvoiceDraftCreated,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                null,
                $snapshot,
                array_keys($snapshot),
            );

            return $invoice;
        }, 3);
    }

    private function bankAccount(mixed $uuid, string $currency): ?BankAccount
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $account = BankAccount::query()
            ->where('uuid', $uuid)
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();

        if ($account->currency !== $currency) {
            throw ValidationException::withMessages([
                'bank_account_uuid' => 'Měna bankovního účtu musí odpovídat měně faktury.',
            ]);
        }

        return $account;
    }

    /** @param list<array<string, mixed>> $items @return array<string, VatRate> */
    private function vatRates(array $items, CarbonImmutable $taxDate, bool $isVatPayer): array
    {
        $rates = [];

        foreach ($items as $item) {
            $uuid = (string) $item['vat_rate_uuid'];

            if (isset($rates[$uuid])) {
                continue;
            }

            VatRate::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $rate = $this->vatRateService->resolveForDate($uuid, $taxDate);

            if (! $isVatPayer && ! $rate->tax_type->allowedAsNonPayerDefault()) {
                throw ValidationException::withMessages([
                    'items' => 'Neplátce DPH může použít pouze osvobozené plnění nebo plnění mimo předmět DPH.',
                ]);
            }

            $rates[$uuid] = $rate;
        }

        return $rates;
    }

    private function storeSupplierSnapshot(Invoice $invoice, CompanySetting $supplier): void
    {
        $snapshot = new InvoiceSupplierSnapshot;
        $snapshot->forceFill([
            'invoice_id' => $invoice->id,
            ...$supplier->only([
                'legal_name', 'additional_name', 'registration_number', 'tax_id', 'vat_id',
                'street', 'house_number', 'orientation_number', 'city', 'postal_code',
                'country_code', 'email', 'phone', 'website', 'is_vat_payer',
                'vat_registered_on', 'invoice_intro', 'invoice_outro',
            ]),
        ])->save();
    }

    private function storeCustomerSnapshot(Invoice $invoice, Client $customer): void
    {
        $snapshot = new InvoiceCustomerSnapshot;
        $snapshot->forceFill([
            'invoice_id' => $invoice->id,
            'source_client_uuid' => $customer->uuid,
            'client_type' => $customer->type->value,
            ...$customer->only([
                'display_name', 'company_name', 'first_name', 'last_name', 'registration_number',
                'tax_id', 'vat_id', 'email', 'phone', 'website', 'contact_person', 'street',
                'house_number', 'orientation_number', 'city', 'postal_code', 'country_code',
                'delivery_name', 'delivery_street', 'delivery_house_number',
                'delivery_orientation_number', 'delivery_city', 'delivery_postal_code',
                'delivery_country_code', 'language',
            ]),
        ])->save();
    }

    private function storeBankSnapshot(Invoice $invoice, BankAccount $account): void
    {
        $snapshot = new InvoiceBankAccountSnapshot;
        $snapshot->forceFill([
            'invoice_id' => $invoice->id,
            'source_bank_account_uuid' => $account->uuid,
            ...$account->only([
                'name', 'domestic_prefix', 'domestic_account_number', 'bank_code',
                'iban', 'bic', 'currency',
            ]),
        ])->save();
    }

    /** @param array<string, VatRate> $rates @return array<string, InvoiceVatSnapshot> */
    private function storeVatSnapshots(Invoice $invoice, array $rates): array
    {
        $snapshots = [];

        foreach ($rates as $uuid => $rate) {
            $snapshot = new InvoiceVatSnapshot;
            $snapshot->forceFill([
                'invoice_id' => $invoice->id,
                'source_vat_rate_uuid' => $rate->uuid,
                'name' => $rate->name,
                'code' => $rate->code,
                'tax_type' => $rate->tax_type->value,
                'percentage' => $rate->percentage,
            ])->save();
            $snapshots[$uuid] = $snapshot;
        }

        return $snapshots;
    }

    /** @param list<array<string, mixed>> $items @param array<string, InvoiceVatSnapshot> $vatSnapshots */
    private function storeItems(Invoice $invoice, array $items, array $vatSnapshots): void
    {
        foreach (array_values($items) as $index => $attributes) {
            try {
                $quantity = InvoiceDecimal::quantity(is_int($attributes['quantity']) ? $attributes['quantity'] : (string) $attributes['quantity']);
                $unitPrice = InvoiceDecimal::money(is_int($attributes['unit_price']) ? $attributes['unit_price'] : (string) $attributes['unit_price']);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['items' => $exception->getMessage()]);
            }

            $item = new InvoiceItem;
            $item->fill([
                'position' => $index + 1,
                'description' => trim((string) $attributes['description']),
                'quantity' => $quantity,
                'unit' => isset($attributes['unit']) ? trim((string) $attributes['unit']) ?: null : null,
                'unit_price' => $unitPrice,
            ]);
            $item->forceFill([
                'invoice_id' => $invoice->id,
                'invoice_vat_snapshot_id' => $vatSnapshots[(string) $attributes['vat_rate_uuid']]->id,
            ])->save();
        }
    }
}
