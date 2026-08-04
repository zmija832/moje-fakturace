<?php

namespace Tests\Feature;

use App\Domain\Audit\BusinessAuditRequestContext;
use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\BusinessConnection;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business;
use App\Models\Business\AuditLog;
use App\Models\Business\DocumentSequence;
use App\Models\User;
use App\Services\Business\BankAccountService;
use App\Services\Business\BusinessAuditWriter;
use App\Services\Business\ClientService;
use App\Services\Business\CompanySettingsService;
use App\Services\Business\DocumentNumberAllocator;
use App\Services\Business\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class BusinessAuditTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected array $businessDatabaseTransactionExclusions = [
        'test_audit_insert_failure_rolls_back_domain_change',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_audit_model_is_fail_closed_server_uuid_and_immutable(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        try {
            AuditLog::query()->count();
            $this->fail('Audit bez Business Contextu měl selhat.');
        } catch (MissingBusinessContext) {
            $this->assertSame([], DB::connection('central')->getQueryLog());
        }

        $this->activateBusiness(BusinessConnection::Business1);
        $audit = $this->rawAudit();
        $this->assertTrue(Str::isUuid($audit->uuid));
        $this->assertSame([], $audit->getFillable());

        try {
            $audit->actor_name = 'Změna';
            $audit->save();
            $this->fail('Auditní záznam neměl jít změnit.');
        } catch (LogicException) {
            $this->assertSame('Systém', $audit->fresh()->actor_name);
        }

        $this->expectException(LogicException::class);
        $audit->delete();
    }

    public function test_writer_refuses_to_create_audit_outside_domain_transaction(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);

        $this->expectException(LogicException::class);
        app(BusinessAuditWriter::class)->write(
            BusinessAuditEvent::ClientCreated,
            BusinessAuditableType::Client,
            (string) Str::uuid(),
            null,
            ['display_name' => 'Test'],
            ['display_name'],
        );
    }

    public function test_all_required_domain_events_are_written_with_actor_and_request_context(): void
    {
        $business = $this->activateBusiness(BusinessConnection::Business1);
        $user = User::factory()->create(['name' => 'Auditní správce', 'email' => 'audit@example.test']);
        $user->businesses()->attach($business, ['role' => 'admin']);
        $this->actingAs($user);
        $requestId = (string) Str::uuid();
        app(BusinessAuditRequestContext::class)->setRequestId($requestId);

        $settings = app(CompanySettingsService::class);
        $settings->save($this->companyAttributes());
        $settings->save($this->companyAttributes(['legal_name' => 'Upravený subjekt']));

        $banks = app(BankAccountService::class);
        $bank = $banks->create($this->bankAttributes());
        $banks->update($bank->uuid, $this->bankAttributes(['name' => 'Upravený účet']));
        $banks->deactivate($bank->uuid);
        $banks->activate($bank->uuid);
        $banks->setDefault($bank->uuid);
        $banks->deactivate($bank->uuid);
        $banks->activate($bank->uuid);
        $banks->setDefault($bank->uuid);
        $banks->archive($bank->uuid);

        $clients = app(ClientService::class);
        $client = $clients->create($this->clientAttributes());
        $clients->update($client->uuid, $this->clientAttributes(['display_name' => 'Upravený klient']));
        $clients->deactivate($client->uuid);
        $clients->activate($client->uuid);
        $clients->archive($client->uuid);

        $sequences = app(DocumentSequenceService::class);
        $sequence = $sequences->create($this->sequenceAttributes(['prefix' => 'AUD-']));
        $sequences->update($sequence->uuid, $this->sequenceAttributes(['prefix' => 'AUD2-']));
        $sequences->deactivate($sequence->uuid);
        $sequences->activate($sequence->uuid);
        $sequences->setDefault($sequence->uuid);
        $sequences->deactivate($sequence->uuid);
        $sequences->activate($sequence->uuid);
        $sequences->setDefault($sequence->uuid);
        $sequences->archive($sequence->uuid);

        $allocationSequence = $sequences->create($this->sequenceAttributes(['prefix' => 'ALLOC-']));
        $correlation = (string) Str::uuid();
        $allocator = app(DocumentNumberAllocator::class);
        $firstAllocation = $allocator->allocate($allocationSequence->uuid, CarbonImmutable::parse('2026-07-31'), $correlation);
        $repeatedAllocation = $allocator->allocate($allocationSequence->uuid, CarbonImmutable::parse('2026-07-31'), $correlation);
        $this->assertSame($firstAllocation->id, $repeatedAllocation->id);

        $requiredEvents = [
            'company_settings.created', 'company_settings.updated',
            'bank_account.created', 'bank_account.updated', 'bank_account.activated',
            'bank_account.deactivated', 'bank_account.archived',
            'bank_account.default_changed', 'bank_account.default_removed',
            'client.created', 'client.updated', 'client.activated', 'client.deactivated', 'client.archived',
            'document_sequence.created', 'document_sequence.updated', 'document_sequence.activated',
            'document_sequence.deactivated', 'document_sequence.archived',
            'document_sequence.default_changed', 'document_sequence.default_removed',
            'document_number.allocated',
        ];

        foreach ($requiredEvents as $event) {
            $this->assertTrue(AuditLog::query()->where('event', $event)->exists(), $event);
        }

        $createdBankAudit = AuditLog::query()->where('event', 'bank_account.created')->firstOrFail();
        $this->assertSame('central-user:'.$user->id, $createdBankAudit->actor_user_uuid);
        $this->assertSame('Auditní správce', $createdBankAudit->actor_name);
        $this->assertSame('audit@example.test', $createdBankAudit->actor_email);
        $this->assertSame($requestId, $createdBankAudit->request_id);
        $this->assertSame($bank->uuid, $createdBankAudit->auditable_uuid);
        $this->assertNotNull($createdBankAudit->occurred_at);
        $this->assertStringNotContainsString('CZ6508000000192000145399', json_encode($createdBankAudit->new_values));
        $this->assertStringContainsString('••••5399', json_encode($createdBankAudit->new_values, JSON_UNESCAPED_UNICODE));

        $this->assertSame(1, AuditLog::query()
            ->where('event', 'document_number.allocated')
            ->where('auditable_uuid', $correlation)
            ->count());
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertFalse(Schema::connection('central')->hasTable('audit_logs'));
    }

    public function test_no_change_updates_do_not_create_false_audits(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $settings = app(CompanySettingsService::class);
        $settings->save($this->companyAttributes());
        $settings->save($this->companyAttributes());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'company_settings')->count());

        $bank = app(BankAccountService::class)->create($this->bankAttributes());
        app(BankAccountService::class)->update($bank->uuid, $this->bankAttributes());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'bank_account')->count());

        $client = app(ClientService::class)->create($this->clientAttributes());
        app(ClientService::class)->update($client->uuid, $this->clientAttributes());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'client')->count());

        $sequence = app(DocumentSequenceService::class)->create($this->sequenceAttributes());
        app(DocumentSequenceService::class)->update($sequence->uuid, $this->sequenceAttributes());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'document_sequence')->count());
    }

    public function test_audits_are_physically_isolated_and_same_uuid_can_exist_in_both_databases(): void
    {
        $sharedUuid = (string) Str::uuid();
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->rawAudit($sharedUuid, 'První databáze');
        app(BankAccountService::class)->create($this->bankAttributes(['name' => 'B1 účet']));

        $this->activateBusiness(BusinessConnection::Business2);
        $second = $this->rawAudit($sharedUuid, 'Druhá databáze');
        app(BankAccountService::class)->create($this->bankAttributes(['name' => 'B2 účet']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, DB::connection('business_1')->table('audit_logs')->count());
        $this->assertSame(2, DB::connection('business_2')->table('audit_logs')->count());
        $this->assertFalse(Schema::connection('central')->hasTable('audit_logs'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    #[DataProvider('rollbackModules')]
    public function test_audit_insert_failure_rolls_back_domain_change(string $module): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $sequence = null;

        if ($module === 'allocation') {
            $sequence = app(DocumentSequenceService::class)->create($this->sequenceAttributes());
        }

        Schema::connection('business_1')->drop('audit_logs');

        try {
            match ($module) {
                'company' => app(CompanySettingsService::class)->save($this->companyAttributes()),
                'bank' => app(BankAccountService::class)->create($this->bankAttributes()),
                'client' => app(ClientService::class)->create($this->clientAttributes()),
                'sequence' => app(DocumentSequenceService::class)->create($this->sequenceAttributes()),
                'allocation' => app(DocumentNumberAllocator::class)->allocate(
                    $sequence->uuid,
                    CarbonImmutable::parse('2026-01-01'),
                ),
            };
            $this->fail("Modul {$module} měl selhat při auditním insertu.");
        } catch (QueryException) {
            match ($module) {
                'company' => $this->assertSame(0, DB::connection('business_1')->table('company_settings')->count()),
                'bank' => $this->assertSame(0, DB::connection('business_1')->table('bank_accounts')->count()),
                'client' => $this->assertSame(0, DB::connection('business_1')->table('clients')->count()),
                'sequence' => $this->assertSame(0, DB::connection('business_1')->table('document_sequences')->count()),
                'allocation' => $this->assertAllocationRollback($sequence),
            };
        }
    }

    /** @return array<string, array{string}> */
    public static function rollbackModules(): array
    {
        return [
            'company settings' => ['company'], 'bank account' => ['bank'],
            'client' => ['client'], 'document sequence' => ['sequence'],
            'number allocation' => ['allocation'],
        ];
    }

    private function assertAllocationRollback(DocumentSequence $sequence): void
    {
        $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->assertSame(1, $sequence->fresh()->next_number);
        $this->assertNull($sequence->fresh()->current_period);
    }

    private function activateBusiness(BusinessConnection $connection): Business
    {
        $business = Business::query()->create([
            'uuid' => (string) Str::uuid(), 'display_name' => 'Subjekt '.$connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);
        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    private function rawAudit(?string $uuid = null, string $actor = 'Systém'): AuditLog
    {
        $audit = new AuditLog;
        $audit->forceFill([
            'uuid' => $uuid, 'event' => 'client.created', 'actor_name' => $actor,
            'auditable_type' => 'client', 'occurred_at' => now(),
        ])->save();

        return $audit;
    }

    /** @param array<string, mixed> $overrides */
    private function companyAttributes(array $overrides = []): array
    {
        return array_replace([
            'legal_name' => 'Testovací subjekt', 'additional_name' => null, 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'vat_id' => null, 'street' => 'Testovací', 'house_number' => '1',
            'orientation_number' => null, 'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
            'email' => 'firma@example.test', 'phone' => '+420123456789', 'website' => 'https://example.test',
            'default_currency' => 'CZK', 'document_locale' => 'cs', 'timezone' => 'Europe/Prague',
            'is_vat_payer' => false, 'vat_registered_on' => null, 'default_due_days' => 14,
            'default_payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'invoice_intro' => null, 'invoice_outro' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function bankAttributes(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Provozní účet', 'domestic_prefix' => null,
            'domestic_account_number' => '123456789', 'bank_code' => '0800',
            'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX',
            'currency' => 'CZK', 'is_active' => true, 'sort_order' => 10, 'note' => 'Citlivá poznámka',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function clientAttributes(array $overrides = []): array
    {
        return array_replace([
            'type' => 'company', 'display_name' => 'Testovací klient', 'company_name' => 'Testovací s.r.o.',
            'first_name' => null, 'last_name' => null, 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'vat_id' => null, 'email' => 'client@example.test',
            'phone' => '+420123456789', 'website' => null, 'contact_person' => 'Jana Nováková',
            'street' => 'Soukromá', 'house_number' => '10', 'orientation_number' => null,
            'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
            'delivery_name' => null, 'delivery_street' => null, 'delivery_house_number' => null,
            'delivery_orientation_number' => null, 'delivery_city' => null,
            'delivery_postal_code' => null, 'delivery_country_code' => null,
            'default_currency' => 'CZK', 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'language' => 'cs',
            'note' => 'Citlivá klientská poznámka', 'is_active' => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function sequenceAttributes(array $overrides = []): array
    {
        return array_replace([
            'document_type' => 'issued_invoice', 'name' => 'Faktury', 'prefix' => 'FV-',
            'suffix' => '', 'year_format' => 'yyyy', 'sequence_digits' => 5,
            'start_number' => 1, 'reset_period' => 'yearly', 'is_active' => true, 'sort_order' => 10,
        ], $overrides);
    }
}
