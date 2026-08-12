<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\ClientType;
use App\Enums\DefaultPaymentMethod;
use App\Enums\DocumentType;
use App\Enums\InvoiceDiscountType;
use App\Enums\VatTaxType;
use App\Models\Business\BankAccount;
use App\Models\Business\BankAccountDefault;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use App\Models\Business\VatRate;
use App\Models\Business\VatRateDefault;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class InvoiceFormOptions
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly DocumentSequenceService $sequenceService,
    ) {}

    /** @return array<string, mixed> */
    public function forDate(string $taxableSupplyOn): array
    {
        $this->connectionResolver->resolve();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $taxableSupplyOn) ?: today()->toImmutable();
        $clients = Client::query()->whereNull('archived_at')->where('is_active', true)
            ->orderBy('display_name')->limit(201)->get();
        $accounts = BankAccount::query()->with('defaultAssignment')->whereNull('archived_at')
            ->where('is_active', true)->orderBy('currency')->orderBy('sort_order')->orderBy('name')->get();
        $rates = VatRate::query()->whereNull('archived_at')->where('is_active', true)
            ->where('tax_type', '!=', VatTaxType::NonPayer->value)
            ->whereDate('valid_from', '<=', $date)->where(fn ($query) => $query
            ->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderBy('sort_order')->orderBy('name')->get();
        $sequences = DocumentSequence::query()->with('defaultAssignment')
            ->where('document_type', DocumentType::IssuedInvoice->value)->whereNull('archived_at')
            ->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $company = CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->first();
        $defaultVatRate = VatRateDefault::query()->with('rate')
            ->where('context', 'sales')
            ->first()
            ?->rate;

        return [
            'clients' => $clients->take(200)->values(),
            'clientsTruncated' => $clients->count() > 200,
            'bankAccounts' => $accounts,
            'vatRates' => $rates,
            'documentSequences' => $sequences,
            'sequencePreviews' => $sequences->mapWithKeys(fn (DocumentSequence $sequence): array => [
                $sequence->uuid => $this->sequenceService->previewModel($sequence, $date),
            ]),
            'defaultSequenceUuid' => DocumentSequenceDefault::query()->with('sequence')
                ->where('document_type', DocumentType::IssuedInvoice->value)->first()?->sequence?->uuid,
            'defaultBankAccounts' => BankAccountDefault::query()->with('account')->get()
                ->mapWithKeys(fn (BankAccountDefault $default): array => [$default->currency => $default->account?->uuid]),
            'defaultVatRateUuid' => $defaultVatRate !== null && ! $defaultVatRate->isSystemManaged()
                ? $defaultVatRate->uuid
                : null,
            'companySettings' => $company,
            'isVatPayer' => (bool) $company?->is_vat_payer,
            'clientTypes' => ClientType::options(),
            'countries' => CompanySettingOptions::COUNTRIES,
            'currencies' => CompanySettingOptions::CURRENCIES,
            'paymentMethods' => DefaultPaymentMethod::options(),
            'discountTypes' => collect(InvoiceDiscountType::cases())->mapWithKeys(
                fn (InvoiceDiscountType $type): array => [$type->value => $type->label()],
            )->all(),
        ];
    }

    /** @return Collection<int, Client> */
    public function clientsForIndex(): Collection
    {
        $this->connectionResolver->resolve();

        return Client::query()->whereNull('archived_at')->where('is_active', true)
            ->orderBy('display_name')->limit(200)->get();
    }

    /** @return array<string, mixed> */
    public function defaults(?Client $client = null): array
    {
        $company = CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->first();
        $issuedOn = today()->format('Y-m-d');
        $dueDays = $client?->default_due_days ?? $company?->default_due_days ?? 14;

        return [
            'currency' => $client?->default_currency ?? $company?->default_currency ?? 'CZK',
            'payment_method' => $client?->default_payment_method ?? $company?->default_payment_method ?? 'bank_transfer',
            'issued_on' => $issuedOn,
            'taxable_supply_on' => $issuedOn,
            'due_on' => today()->addDays((int) $dueDays)->format('Y-m-d'),
        ];
    }
}
