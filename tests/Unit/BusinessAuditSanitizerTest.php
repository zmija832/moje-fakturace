<?php

namespace Tests\Unit;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Enums\BusinessAuditableType;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentNumberAllocation;
use App\Models\Business\DocumentSequence;
use App\Models\Business\VatRate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BusinessAuditSanitizerTest extends TestCase
{
    public function test_sensitive_company_bank_and_client_values_are_filtered_or_masked(): void
    {
        $sanitizer = new BusinessAuditSanitizer;
        $company = new CompanySetting;
        $company->forceFill([
            'legal_name' => 'Bezpečná firma', 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'vat_id' => 'VAT87654321', 'street' => 'Tajná 10',
            'house_number' => '10', 'email' => 'firma@example.test', 'phone' => '+420111222333',
            'website' => 'https://secret.test', 'city' => 'Praha', 'country_code' => 'CZ',
            'default_currency' => 'CZK', 'document_locale' => 'cs', 'timezone' => 'Europe/Prague',
            'is_vat_payer' => true, 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'invoice_intro' => 'Tajný úvod faktury',
            'invoice_outro' => 'Tajný konec faktury', 'password' => 'super-secret-password',
            'token' => 'secret-token', 'session_id' => 'secret-session', 'connection_name' => 'business_2',
        ]);
        $bank = new BankAccount;
        $bank->forceFill([
            'name' => 'Provozní účet', 'currency' => 'CZK', 'is_active' => true,
            'sort_order' => 1, 'domestic_prefix' => '019',
            'domestic_account_number' => '000123456789', 'bank_code' => '0800',
            'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX',
            'note' => 'Citlivá bankovní poznámka',
        ]);
        $client = new Client;
        $client->forceFill([
            'type' => 'company', 'display_name' => 'Bezpečný klient', 'company_name' => 'Klient s.r.o.',
            'registration_number' => '87654321', 'tax_id' => 'CZ87654321', 'vat_id' => 'SK1234567890',
            'street' => 'Soukromá 99', 'house_number' => '99', 'city' => 'Brno',
            'country_code' => 'CZ', 'email' => 'client@example.test', 'phone' => '+420999888777',
            'contact_person' => 'Citlivá osoba', 'note' => 'Citlivá poznámka klienta',
            'default_currency' => 'CZK', 'is_active' => true,
        ]);

        $payload = json_encode([
            $sanitizer->snapshot(BusinessAuditableType::CompanySettings, $company),
            $sanitizer->snapshot(BusinessAuditableType::BankAccount, $bank),
            $sanitizer->snapshot(BusinessAuditableType::Client, $client),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach ([
            'Tajná 10', 'firma@example.test', '+420111222333', 'secret.test',
            'Tajný úvod faktury', 'Tajný konec faktury', 'super-secret-password',
            'secret-token', 'secret-session', 'business_2', '019', '000123456789',
            'CZ6508000000192000145399', 'GIBACZPX', 'Citlivá bankovní poznámka',
            'Soukromá 99', 'client@example.test', '+420999888777', 'Citlivá osoba',
            'Citlivá poznámka klienta', 'CZ87654321', 'SK1234567890',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }

        $this->assertStringContainsString('••••5678', $payload);
        $this->assertStringContainsString('••••6789', $payload);
        $this->assertStringContainsString('••••5399', $payload);
        $this->assertStringContainsString('Bezpečná firma', $payload);
        $this->assertStringContainsString('Bezpečný klient', $payload);
    }

    public function test_sensitive_changes_are_names_only_and_sequence_allocation_whitelists_are_explicit(): void
    {
        $sanitizer = new BusinessAuditSanitizer;
        $bank = new BankAccount;
        $bank->forceFill(['iban' => 'OLD-SECRET', 'note' => 'OLD-NOTE']);
        $bank->syncOriginal();
        $bank->forceFill(['iban' => 'NEW-SECRET', 'note' => 'NEW-NOTE']);

        $this->assertSame(['iban', 'note'], $sanitizer->changedFields(BusinessAuditableType::BankAccount, $bank));
        $this->assertStringNotContainsString('NEW-SECRET', json_encode(
            $sanitizer->snapshot(BusinessAuditableType::BankAccount, $bank),
            JSON_THROW_ON_ERROR,
        ));

        $sequence = new DocumentSequence;
        $sequence->forceFill([
            'uuid' => '10000000-0000-4000-8000-000000000001', 'document_type' => 'issued_invoice',
            'name' => 'Faktury', 'prefix' => 'FV-', 'suffix' => '', 'year_format' => 'yyyy',
            'sequence_digits' => 5, 'start_number' => 1, 'reset_period' => 'yearly',
            'is_active' => true, 'sort_order' => 1,
        ]);
        $allocation = new DocumentNumberAllocation;
        $allocation->forceFill([
            'correlation_uuid' => '20000000-0000-4000-8000-000000000002',
            'document_type' => 'issued_invoice', 'period' => '2026',
            'sequence_number' => 12, 'formatted_number' => 'FV-202600012',
        ]);
        $allocation->setRelation('sequence', $sequence);

        $this->assertSame('FV-', $sanitizer->snapshot(BusinessAuditableType::DocumentSequence, $sequence)['prefix']);
        $this->assertSame('FV-202600012', $sanitizer->snapshot(BusinessAuditableType::DocumentNumberAllocation, $allocation)['formatted_number']);
        $this->assertArrayNotHasKey('connection', $sanitizer->snapshot(BusinessAuditableType::DocumentSequence, $sequence));
    }

    public function test_vat_rate_snapshot_uses_exact_decimal_and_explicit_fields(): void
    {
        $rate = new VatRate;
        $rate->setRawAttributes([
            'name' => 'Základní', 'code' => 'STANDARD', 'tax_type' => 'standard',
            'percentage' => '21.0000', 'valid_from' => CarbonImmutable::parse('2026-01-01'), 'valid_to' => null,
            'is_active' => true, 'sort_order' => 10,
        ], true);
        $snapshot = (new BusinessAuditSanitizer)->snapshot(BusinessAuditableType::VatRate, $rate);

        $this->assertSame('21.0000', $snapshot['percentage']);
        $this->assertSame('standard', $snapshot['tax_type']);
        $this->assertArrayNotHasKey('connection', $snapshot);
    }
}
