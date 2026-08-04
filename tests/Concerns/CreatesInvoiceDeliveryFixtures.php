<?php

namespace Tests\Concerns;

use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use App\Models\Business\Invoice;
use App\Models\Business\VatRate;
use App\Models\User;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceIssuer;
use Illuminate\Support\Str;

trait CreatesInvoiceDeliveryFixtures
{
    /** @return array{User,Business} */
    protected function deliveryMembership(string $role = 'admin', BusinessConnection $connection = BusinessConnection::Business1, ?Business $business = null): array
    {
        $user = User::factory()->create();
        $business ??= Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => $connection === BusinessConnection::Business1 ? 'První subjekt' : 'Druhý subjekt',
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection === BusinessConnection::Business1 ? 'S1' : 'S2',
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $user->businesses()->attach($business, ['role' => $role]);

        return [$user, $business];
    }

    /** @return array<string,string> */
    protected function deliveryBusinessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    /** @return array{Invoice,Client,BankAccount,VatRate} */
    protected function createIssuedInvoice(bool $issue = true): array
    {
        $company = new CompanySetting;
        $company->forceFill([
            'singleton_key' => '1', 'legal_name' => 'Žluťoučký dodavatel s.r.o.', 'registration_number' => '12345678',
            'street' => 'Dodavatelská', 'house_number' => '10', 'city' => 'Praha', 'postal_code' => '11000',
            'country_code' => 'CZ', 'email' => 'dodavatel@example.test', 'default_currency' => 'CZK',
            'document_locale' => 'cs', 'timezone' => 'Europe/Prague', 'is_vat_payer' => false,
            'default_due_days' => 14, 'default_payment_method' => 'bank_transfer',
            'invoice_intro' => 'Úvod před položkami.', 'invoice_outro' => 'Děkujeme za spolupráci.',
        ])->save();
        $client = new Client;
        $client->forceFill([
            'type' => 'company', 'display_name' => 'Příliš žluťoučký klient', 'company_name' => 'Příliš žluťoučký klient s.r.o.',
            'registration_number' => '87654321', 'email' => 'snapshot@example.test', 'street' => 'Klientská',
            'house_number' => '1', 'city' => 'Brno', 'postal_code' => '60200', 'country_code' => 'CZ',
            'default_currency' => 'CZK', 'default_due_days' => 14, 'default_payment_method' => 'bank_transfer', 'is_active' => true,
        ])->save();
        $account = new BankAccount;
        $account->forceFill([
            'name' => 'Hlavní účet', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX',
            'currency' => 'CZK', 'is_active' => true, 'sort_order' => 0,
        ])->save();
        $rate = new VatRate;
        $rate->forceFill([
            'name' => 'Mimo DPH', 'code' => 'OUT', 'tax_type' => 'out_of_scope', 'percentage' => null,
            'valid_from' => '2026-01-01', 'is_active' => true, 'sort_order' => 0,
        ])->save();
        $sequence = new DocumentSequence;
        $sequence->forceFill([
            'document_type' => 'issued_invoice', 'name' => 'Faktury', 'prefix' => 'FV-', 'suffix' => '',
            'year_format' => 'yyyy', 'sequence_digits' => 5, 'start_number' => 1, 'next_number' => 1,
            'reset_period' => 'yearly', 'is_active' => true, 'sort_order' => 0,
        ])->save();
        $default = new DocumentSequenceDefault;
        $default->forceFill(['document_type' => 'issued_invoice', 'document_sequence_id' => $sequence->id])->save();
        $draft = app(InvoiceDraftService::class)->create([
            'customer_uuid' => $client->uuid, 'bank_account_uuid' => $account->uuid, 'currency' => 'CZK',
            'issued_on' => '2026-08-02', 'taxable_supply_on' => '2026-08-02', 'due_on' => '2026-08-16',
            'payment_method' => 'bank_transfer', 'variable_symbol' => '20260001', 'note' => 'Poznámka faktury',
            'invoice_discount_type' => 'none', 'invoice_discount_value' => '0',
            'items' => [[
                'position' => 1, 'description' => 'Bezpečná služba', 'quantity' => '1', 'unit' => 'ks',
                'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => '0', 'vat_rate_uuid' => $rate->uuid,
            ]],
        ]);
        $invoice = $issue ? app(InvoiceIssuer::class)->issue($draft->uuid, 1, (string) Str::uuid()) : $draft;

        return [$invoice, $client, $account, $rate];
    }
}
