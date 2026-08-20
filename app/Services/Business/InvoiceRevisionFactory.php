<?php

namespace App\Services\Business;

use App\Domain\Invoices\Exceptions\InvoiceIssuedImmutable;
use App\Domain\Invoices\InvoiceCalculator;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\InvoiceStatus;
use App\Enums\VatTaxType;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceBankAccountSnapshot;
use App\Models\Business\InvoiceCustomerSnapshot;
use App\Models\Business\InvoiceItem;
use App\Models\Business\InvoiceRevision;
use App\Models\Business\InvoiceSupplierSnapshot;
use App\Models\Business\InvoiceVatSnapshot;
use App\Models\Business\InvoiceVatSummary;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class InvoiceRevisionFactory
{
    public function __construct(
        private readonly InvoiceVatResolver $vatResolver,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function prepare(array $attributes): array
    {
        $this->requireBusinessTransaction();
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
            ->where('uuid', (string) ($attributes['customer_uuid'] ?? ''))
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
        $currency = (string) ($attributes['currency'] ?? '');
        $bankAccount = $this->bankAccount($attributes['bank_account_uuid'] ?? null, $currency);
        $items = $this->normalizeItems((array) ($attributes['items'] ?? []));
        $taxDate = CarbonImmutable::parse((string) ($attributes['taxable_supply_on'] ?? ''));
        $resolved = $this->vatResolver->resolve($items, $taxDate, (bool) $supplier->is_vat_payer, true);
        $items = $resolved['items'];
        $rates = $resolved['rates'];
        $vatPayloads = [];
        $calculatorRates = [];

        foreach ($rates as $requestUuid => $rate) {
            $percentage = $rate->percentage === null ? null : (string) $rate->percentage;
            $vatPayloads[$requestUuid] = [
                'source_vat_rate_uuid' => $rate->uuid,
                'name' => $rate->name,
                'code' => $rate->code,
                'tax_type' => $rate->tax_type->value,
                'percentage' => $percentage,
            ];
            $calculatorRates[$requestUuid] = [
                'tax_type' => $rate->tax_type,
                'percentage' => $percentage,
            ];
        }
        ksort($vatPayloads);
        $calculation = $this->calculate($items, $calculatorRates, [
            'type' => $attributes['invoice_discount_type'] ?? 'none',
            'value' => $attributes['invoice_discount_value'] ?? null,
        ], $currency === 'CZK' && ($attributes['payment_method'] ?? null) === 'cash' ? 0 : 2);

        return [
            'header' => [
                'currency' => $currency,
                'issued_on' => CarbonImmutable::parse((string) $attributes['issued_on'])->format('Y-m-d'),
                'taxable_supply_on' => $taxDate->format('Y-m-d'),
                'due_on' => CarbonImmutable::parse((string) $attributes['due_on'])->format('Y-m-d'),
                'payment_method' => (string) $attributes['payment_method'],
                'variable_symbol' => $this->nullableTrimmed($attributes['variable_symbol'] ?? null),
                'note' => $this->nullableTrimmed($attributes['note'] ?? null),
            ],
            'supplier' => $this->supplierPayload($supplier),
            'customer' => $this->customerPayload($customer),
            'bank_account' => $bankAccount === null ? null : $this->bankPayload($bankAccount),
            'vat_snapshots' => $vatPayloads,
            ...$calculation,
        ];
    }

    /** @param array<string, mixed> $prepared */
    public function persist(Invoice $invoice, int $revisionNumber, array $prepared): InvoiceRevision
    {
        $this->requireBusinessTransaction();

        if ($invoice->status === InvoiceStatus::Issued) {
            throw InvoiceIssuedImmutable::mutationDenied();
        }

        return $this->persistPrepared($invoice, $revisionNumber, $prepared);
    }

    /** @param array<string, mixed> $prepared */
    public function persistIssuedCorrection(Invoice $invoice, int $revisionNumber, array $prepared): InvoiceRevision
    {
        $this->requireBusinessTransaction();

        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new LogicException('Admin revizi lze vytvořit pouze pro vystavenou fakturu.');
        }

        return $this->persistPrepared($invoice, $revisionNumber, $prepared);
    }

    public function persistForAutomaticVariableSymbol(
        Invoice $invoice,
        InvoiceRevision $source,
        string $variableSymbol,
    ): InvoiceRevision {
        $this->requireBusinessTransaction();

        if ($invoice->status !== InvoiceStatus::Draft || (int) $source->invoice_id !== (int) $invoice->id) {
            throw new LogicException('Automatický variabilní symbol lze doplnit pouze do aktuální revize konceptu.');
        }

        $prepared = $this->payloadFromRevision($source);
        $prepared['header']['variable_symbol'] = $variableSymbol;

        return $this->persistPrepared($invoice, $source->revision_number + 1, $prepared);
    }

    /** @param array<string, mixed> $prepared */
    private function persistPrepared(Invoice $invoice, int $revisionNumber, array $prepared): InvoiceRevision
    {
        $revision = new InvoiceRevision;
        $revision->forceFill([
            'invoice_id' => $invoice->id,
            'revision_number' => $revisionNumber,
            ...$prepared['header'],
            'invoice_discount_type' => $prepared['invoice_discount']['type'],
            'invoice_discount_value' => $prepared['invoice_discount']['value'],
            'invoice_discount_amount' => $prepared['invoice_discount']['amount'],
            ...$prepared['totals'],
            'created_by_actor' => auth()->id() === null ? null : 'central-user:'.auth()->id(),
        ])->save();

        $supplier = new InvoiceSupplierSnapshot;
        $supplier->forceFill(['invoice_revision_id' => $revision->id, ...$prepared['supplier']])->save();
        $customer = new InvoiceCustomerSnapshot;
        $customer->forceFill(['invoice_revision_id' => $revision->id, ...$prepared['customer']])->save();

        if ($prepared['bank_account'] !== null) {
            $bank = new InvoiceBankAccountSnapshot;
            $bank->forceFill(['invoice_revision_id' => $revision->id, ...$prepared['bank_account']])->save();
        }

        $vatSnapshots = [];

        foreach ($prepared['vat_snapshots'] as $requestUuid => $payload) {
            $snapshot = new InvoiceVatSnapshot;
            $snapshot->forceFill(['invoice_revision_id' => $revision->id, ...$payload])->save();
            $vatSnapshots[$requestUuid] = $snapshot;
        }

        foreach ($prepared['items'] as $payload) {
            $item = new InvoiceItem;
            $item->forceFill([
                'invoice_revision_id' => $revision->id,
                'invoice_vat_snapshot_id' => $vatSnapshots[$payload['vat_rate_uuid']]->id,
                ...$this->only($payload, [
                    'position', 'description', 'quantity', 'unit', 'unit_price', 'discount_type',
                    'discount_value', 'line_discount_amount', 'invoice_discount_amount',
                    'unit_price_after_discount', 'line_net_amount', 'vat_amount', 'line_total_amount',
                ]),
            ])->save();
        }

        foreach ($prepared['summaries'] as $payload) {
            $summary = new InvoiceVatSummary;
            $summary->forceFill(['invoice_revision_id' => $revision->id, ...$payload])->save();
        }

        return $revision->load([
            'supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'vatSnapshots',
            'items.vatSnapshot', 'vatSummaries',
        ]);
    }

    /** @param array<string, mixed> $prepared */
    public function matches(InvoiceRevision $revision, array $prepared): bool
    {
        $revision->loadMissing([
            'supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'vatSnapshots',
            'items.vatSnapshot', 'vatSummaries',
        ]);

        return hash_equals(
            $this->fingerprint($prepared),
            $this->fingerprint($this->payloadFromRevision($revision)),
        );
    }

    /** @param array<string, mixed> $prepared */
    public function fingerprint(array $prepared): string
    {
        $json = json_encode($prepared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    private function payloadFromRevision(InvoiceRevision $revision): array
    {
        $vatSnapshots = [];

        foreach ($revision->vatSnapshots->sortBy('source_vat_rate_uuid') as $snapshot) {
            $vatSnapshots[$snapshot->source_vat_rate_uuid] = [
                'source_vat_rate_uuid' => $snapshot->source_vat_rate_uuid,
                'name' => $snapshot->name,
                'code' => $snapshot->code,
                'tax_type' => $snapshot->tax_type->value,
                'percentage' => $snapshot->percentage,
            ];
        }

        $items = [];

        foreach ($revision->items as $item) {
            $items[] = [
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type->value,
                'discount_value' => $item->discount_value,
                'gross_amount' => InvoiceDecimal::multiply($item->quantity, $item->unit_price),
                'discount_amount' => $item->line_discount_amount,
                'line_discount_amount' => $item->line_discount_amount,
                'vat_rate_uuid' => $item->vatSnapshot->source_vat_rate_uuid,
                'invoice_discount_amount' => $item->invoice_discount_amount,
                'unit_price_after_discount' => $item->unit_price_after_discount,
                'line_net_amount' => $item->line_net_amount,
                'vat_amount' => $item->vat_amount,
                'line_total_amount' => $item->line_total_amount,
            ];
        }

        $summaries = [];

        foreach ($revision->vatSummaries as $summary) {
            $summaries[] = [
                'tax_type' => $summary->tax_type->value,
                'percentage' => $summary->percentage,
                'percentage_key' => $summary->percentage_key,
                'tax_base' => $summary->tax_base,
                'vat_amount' => $summary->vat_amount,
                'total_amount' => $summary->total_amount,
            ];
        }

        usort($summaries, fn (array $left, array $right): int => ($left['tax_type'].'|'.$left['percentage_key']) <=> ($right['tax_type'].'|'.$right['percentage_key']));

        return [
            'header' => [
                'currency' => $revision->currency,
                'issued_on' => $revision->issued_on->format('Y-m-d'),
                'taxable_supply_on' => $revision->taxable_supply_on->format('Y-m-d'),
                'due_on' => $revision->due_on->format('Y-m-d'),
                'payment_method' => $revision->payment_method->value,
                'variable_symbol' => $revision->variable_symbol,
                'note' => $revision->note,
            ],
            'supplier' => $this->snapshotPayload($revision->supplierSnapshot, [
                'legal_name', 'additional_name', 'registration_number', 'tax_id', 'vat_id',
                'street', 'house_number', 'orientation_number', 'city', 'postal_code',
                'country_code', 'email', 'phone', 'website', 'is_vat_payer',
                'vat_registered_on', 'invoice_intro', 'invoice_outro',
            ]),
            'customer' => $this->snapshotPayload($revision->customerSnapshot, [
                'source_client_uuid', 'client_type', 'display_name', 'company_name', 'first_name',
                'last_name', 'registration_number', 'tax_id', 'vat_id', 'email', 'phone',
                'website', 'contact_person', 'street', 'house_number', 'orientation_number',
                'city', 'postal_code', 'country_code', 'delivery_name', 'delivery_street',
                'delivery_house_number', 'delivery_orientation_number', 'delivery_city',
                'delivery_postal_code', 'delivery_country_code', 'language',
            ]),
            'bank_account' => $revision->bankAccountSnapshot === null ? null : $this->snapshotPayload($revision->bankAccountSnapshot, [
                'source_bank_account_uuid', 'name', 'domestic_prefix', 'domestic_account_number',
                'bank_code', 'iban', 'bic', 'currency',
            ]),
            'vat_snapshots' => $vatSnapshots,
            'items' => $items,
            'summaries' => $summaries,
            'invoice_discount' => [
                'type' => $revision->invoice_discount_type->value,
                'value' => $revision->invoice_discount_value,
                'amount' => $revision->invoice_discount_amount,
            ],
            'totals' => $this->only($revision, [
                'subtotal_before_discount', 'discount_total', 'tax_base_total', 'vat_total',
                'total_before_rounding', 'rounding_adjustment', 'grand_total',
            ]),
        ];
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['items' => 'Položka faktury má neplatný formát.']);
            }

            $normalized[] = [
                'position' => isset($item['position']) ? (int) $item['position'] : $index + 1,
                'description' => trim((string) ($item['description'] ?? '')),
                'quantity' => $item['quantity'] ?? '',
                'unit' => $item['unit'] ?? null,
                'unit_price' => $item['unit_price'] ?? '',
                'discount_type' => (string) ($item['discount_type'] ?? 'none'),
                'discount_value' => $item['discount_value'] ?? null,
                ...array_key_exists('vat_rate_uuid', $item) ? ['vat_rate_uuid' => (string) $item['vat_rate_uuid']] : [],
            ];
        }
        usort($normalized, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        if ($normalized === [] || count(array_unique(array_column($normalized, 'position'))) !== count($normalized)) {
            throw ValidationException::withMessages(['items' => 'Faktura musí mít položky s unikátními pozicemi.']);
        }

        return $normalized;
    }

    private function bankAccount(mixed $uuid, string $currency): ?BankAccount
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $account = BankAccount::query()->where('uuid', $uuid)->whereNull('archived_at')
            ->where('is_active', true)->lockForUpdate()->firstOrFail();

        if ($account->currency !== $currency) {
            throw ValidationException::withMessages([
                'bank_account_uuid' => 'Měna bankovního účtu musí odpovídat měně faktury.',
            ]);
        }

        return $account;
    }

    /** @param list<array<string, mixed>> $items @param array<string, array{tax_type: VatTaxType, percentage: ?string}> $rates @param array{type: mixed, value: mixed} $discount */
    private function calculate(array $items, array $rates, array $discount, int $totalScale): array
    {
        try {
            return $this->calculator->calculate($items, $rates, $discount, $totalScale);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function supplierPayload(CompanySetting $supplier): array
    {
        return [
            ...$this->only($supplier, [
                'legal_name', 'additional_name', 'registration_number', 'tax_id', 'vat_id',
                'street', 'house_number', 'orientation_number', 'city', 'postal_code',
                'country_code', 'email', 'phone', 'website',
            ]),
            'is_vat_payer' => (bool) $supplier->is_vat_payer,
            'vat_registered_on' => $this->date($supplier->vat_registered_on),
            ...$this->only($supplier, ['invoice_intro', 'invoice_outro']),
        ];
    }

    /** @return array<string, mixed> */
    private function customerPayload(Client $customer): array
    {
        return [
            'source_client_uuid' => $customer->uuid,
            'client_type' => $customer->type->value,
            ...$this->only($customer, [
                'display_name', 'company_name', 'first_name', 'last_name', 'registration_number',
                'tax_id', 'vat_id', 'email', 'phone', 'website', 'contact_person', 'street',
                'house_number', 'orientation_number', 'city', 'postal_code', 'country_code',
                'delivery_name', 'delivery_street', 'delivery_house_number',
                'delivery_orientation_number', 'delivery_city', 'delivery_postal_code',
                'delivery_country_code', 'language',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function bankPayload(BankAccount $account): array
    {
        return [
            'source_bank_account_uuid' => $account->uuid,
            ...$this->only($account, [
                'name', 'domestic_prefix', 'domestic_account_number', 'bank_code', 'iban', 'bic', 'currency',
            ]),
        ];
    }

    /** @param list<string> $fields @return array<string, mixed> */
    private function snapshotPayload(object $model, array $fields): array
    {
        $payload = [];

        foreach ($fields as $field) {
            $value = $model->{$field};
            $payload[$field] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            };
        }

        return $payload;
    }

    /** @param list<string> $fields @return array<string, mixed> */
    private function only(object|array $source, array $fields): array
    {
        $payload = [];

        foreach ($fields as $field) {
            $payload[$field] = is_array($source) ? $source[$field] : $source->{$field};
        }

        return $payload;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? null : (string) $value);
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function requireBusinessTransaction(): void
    {
        $model = new Invoice;

        if (DB::connection($model->getConnectionName())->transactionLevel() < 1) {
            throw new LogicException('Revize faktury musí vzniknout uvnitř business transakce.');
        }
    }
}
