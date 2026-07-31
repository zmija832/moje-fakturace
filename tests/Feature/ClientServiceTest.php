<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\Client;
use App\Services\Business\ClientService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class ClientServiceTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        try {
            $this->ensureSafeTestDatabases();
            foreach (BusinessConnection::cases() as $connection) {
                if (Schema::connection($connection->connectionName())->hasTable('clients')) {
                    DB::connection($connection->connectionName())->table('clients')->delete();
                }
            }
            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_model_fails_closed_without_context_before_central_sql(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        try {
            Client::query()->count();
            $this->fail('Client bez contextu měl selhat.');
        } catch (MissingBusinessContext) {
            $this->assertSame([], DB::connection('central')->getQueryLog());
        }
    }

    public function test_clients_are_physically_isolated_even_with_same_uuid_and_id(): void
    {
        $uuid = (string) Str::uuid();
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->forcedUuidClient($uuid, 'První klient');
        $this->assertSame('business_1', $first->getConnectionName());

        $this->activateBusiness(BusinessConnection::Business2);
        $second = $this->forcedUuidClient($uuid, 'Druhý klient');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Druhý klient', $this->service()->find($uuid)->display_name);

        $this->activateBusiness(BusinessConnection::Business1);
        $this->assertSame('První klient', $this->service()->find($uuid)->display_name);
        $this->assertFalse(Schema::connection('central')->hasTable('clients'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_uuid_is_unique_per_database_generated_server_side_and_not_mass_assignable(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $fakeUuid = (string) Str::uuid();
        $client = $this->service()->create($this->companyAttributes([
            'uuid' => $fakeUuid,
            'connection' => 'business_2',
            'connection_name' => 'business_2',
            'archived_at' => now(),
        ]));

        $this->assertNotSame($fakeUuid, $client->uuid);
        $this->assertTrue(Str::isUuid($client->uuid));
        $this->assertNull($client->archived_at);
        $this->assertSame('business_1', $client->getConnectionName());

        $this->expectException(QueryException::class);
        $this->forcedUuidClient($client->uuid, 'Duplicitní UUID');
    }

    public function test_company_is_normalized_and_display_name_is_generated(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $client = $this->service()->create($this->companyAttributes([
            'display_name' => ' ',
            'company_name' => '  Testovací firma s.r.o. ',
            'first_name' => 'Skryté',
            'last_name' => 'Jméno',
            'registration_number' => ' 001 234 56 ',
            'tax_id' => ' CZ 00123456 ',
            'email' => ' INFO@EXAMPLE.TEST ',
            'country_code' => 'cz',
            'default_currency' => 'eur',
            'language' => 'SK',
            'phone' => ' +420 123 456 789 ',
        ]));

        $this->assertSame('Testovací firma s.r.o.', $client->display_name);
        $this->assertSame('Testovací firma s.r.o.', $client->company_name);
        $this->assertNull($client->first_name);
        $this->assertNull($client->last_name);
        $this->assertSame('00123456', $client->registration_number);
        $this->assertSame('CZ00123456', $client->tax_id);
        $this->assertSame('info@example.test', $client->email);
        $this->assertSame('CZ', $client->country_code);
        $this->assertSame('EUR', $client->default_currency);
        $this->assertSame('sk', $client->language);
        $this->assertSame('+420 123 456 789', $client->phone);
        $this->assertSame(14, $client->default_due_days);
        $this->assertTrue($client->isCompany());
        $this->assertSame('Testovací 1, 11000 Praha, CZ', $client->formattedBillingAddress());
    }

    public function test_person_clears_company_fields_and_empty_delivery_address(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $client = $this->service()->create($this->personAttributes([
            'display_name' => null,
            'company_name' => 'Nemá se uložit',
            'registration_number' => '12345678',
            'tax_id' => 'CZ12345678',
            'vat_id' => 'CZ12345678',
            'contact_person' => 'Nemá se uložit',
            'delivery_name' => ' ',
            'delivery_street' => '',
            'delivery_country_code' => '',
        ]));

        $this->assertSame('Jan Novák', $client->display_name);
        $this->assertTrue($client->isPerson());
        $this->assertNull($client->company_name);
        $this->assertNull($client->registration_number);
        $this->assertNull($client->tax_id);
        $this->assertNull($client->vat_id);
        $this->assertNull($client->contact_person);
        $this->assertNull($client->formattedDeliveryAddress());
    }

    public function test_manual_display_name_survives_name_change_and_blank_value_regenerates_it(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $client = $this->service()->create($this->companyAttributes([
            'display_name' => 'Ruční název',
        ]));

        $unchanged = $this->service()->update($client->uuid, $this->companyAttributes([
            'display_name' => 'Ruční název',
            'company_name' => 'Nový právní název',
        ]));
        $this->assertSame('Ruční název', $unchanged->display_name);

        $generated = $this->service()->update($client->uuid, $this->companyAttributes([
            'display_name' => '',
            'company_name' => 'Nový právní název',
        ]));
        $this->assertSame('Nový právní název', $generated->display_name);
    }

    public function test_deactivation_activation_and_archiving_preserve_history(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $client = $this->service()->create($this->companyAttributes());

        $this->assertFalse($this->service()->deactivate($client->uuid)->is_active);
        $this->assertTrue($this->service()->activate($client->uuid)->is_active);
        $archived = $this->service()->archive($client->uuid);

        $this->assertTrue($archived->isArchived());
        $this->assertFalse($archived->is_active);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame($client->uuid, $this->service()->find($client->uuid)->uuid);

        try {
            $this->service()->activate($client->uuid);
            $this->fail('Archivovaný klient neměl jít aktivovat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client', $exception->errors());
        }

        try {
            $this->service()->archive($client->uuid);
            $this->fail('Opakovaná archivace neměla přepsat historický čas archivace.');
        } catch (ModelNotFoundException) {
            $this->assertSame(
                $archived->archived_at->toDateTimeString(),
                $client->fresh()->archived_at->toDateTimeString(),
            );
        }

        $this->expectException(ModelNotFoundException::class);
        $this->service()->update($client->uuid, $this->companyAttributes());
    }

    public function test_search_covers_supported_fields_filters_literal_wildcards_and_safe_sort(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $clients = [
            $this->companyAttributes(['display_name' => 'Alfa Praha', 'company_name' => 'Alfa holding', 'registration_number' => '11111111', 'email' => 'alfa@example.test', 'city' => 'Praha']),
            $this->personAttributes(['display_name' => 'Beta osoba', 'first_name' => 'Božena', 'last_name' => 'Brněnská', 'email' => 'beta@example.test', 'city' => 'Brno', 'is_active' => false]),
            $this->companyAttributes(['display_name' => '100%_ Partner', 'company_name' => 'Speciální firma', 'registration_number' => '22222222', 'city' => 'Ostrava']),
        ];

        foreach ($clients as $attributes) {
            $this->service()->create($attributes);
        }
        $archived = $this->service()->create($this->companyAttributes(['display_name' => 'Archivní společnost']));
        $this->service()->archive($archived->uuid);

        foreach (['Alfa Praha', 'Alfa holding', '11111111', 'alfa@example.test', 'Praha', 'Božena', 'Brněnská'] as $term) {
            $this->assertSame(1, $this->service()->search(['search' => $term])->total(), $term);
        }
        $this->assertSame('100%_ Partner', $this->service()->search(['search' => '%_'])->first()->display_name);
        $this->assertSame(2, $this->service()->search(['type' => 'company'])->total());
        $this->assertSame(1, $this->service()->search(['type' => 'person'])->total());
        $this->assertSame(2, $this->service()->search(['status' => 'active'])->total());
        $this->assertSame(1, $this->service()->search(['status' => 'inactive'])->total());
        $this->assertSame(1, $this->service()->search(['status' => 'archived'])->total());
        $this->assertSame(3, $this->service()->search(['sort' => 'DROP TABLE clients'])->total());
    }

    public function test_search_and_uuid_lookup_never_leak_to_other_business_database(): void
    {
        $this->activateBusiness(BusinessConnection::Business2);
        $foreign = $this->service()->create($this->companyAttributes(['display_name' => 'Pouze druhý subjekt']));

        $this->activateBusiness(BusinessConnection::Business1);
        $this->service()->create($this->companyAttributes(['display_name' => 'Pouze první subjekt']));

        $this->assertSame(0, $this->service()->search(['search' => 'Pouze druhý'])->total());
        $this->expectException(ModelNotFoundException::class);
        $this->service()->find($foreign->uuid);
    }

    public function test_duplicate_business_identifiers_and_emails_are_allowed(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $this->service()->create($this->companyAttributes());
        $this->service()->create($this->companyAttributes(['display_name' => 'Druhá provozovna']));

        $this->assertSame(2, Client::query()->where('registration_number', '12345678')->count());
        $this->assertSame(2, Client::query()->where('email', 'firma@example.test')->count());
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

    private function forcedUuidClient(string $uuid, string $displayName): Client
    {
        $client = new Client;
        $client->forceFill(['uuid' => $uuid]);
        $client->fill($this->companyAttributes(['display_name' => $displayName]));
        $client->save();

        return $client;
    }

    private function service(): ClientService
    {
        return app(ClientService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function companyAttributes(array $overrides = []): array
    {
        return array_replace([
            'type' => 'company', 'display_name' => 'Testovací firma', 'company_name' => 'Testovací firma s.r.o.',
            'first_name' => null, 'last_name' => null, 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'vat_id' => null, 'email' => 'firma@example.test',
            'phone' => '+420 123 456 789', 'website' => 'https://example.test', 'contact_person' => 'Jana Nováková',
            'street' => 'Testovací', 'house_number' => '1', 'orientation_number' => null,
            'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
            'delivery_name' => null, 'delivery_street' => null, 'delivery_house_number' => null,
            'delivery_orientation_number' => null, 'delivery_city' => null,
            'delivery_postal_code' => null, 'delivery_country_code' => null,
            'default_currency' => 'CZK', 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'language' => 'cs',
            'note' => 'Poznámka', 'is_active' => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function personAttributes(array $overrides = []): array
    {
        return array_replace($this->companyAttributes(), [
            'type' => 'person', 'display_name' => 'Jan Novák', 'company_name' => null,
            'first_name' => 'Jan', 'last_name' => 'Novák', 'registration_number' => null,
            'tax_id' => null, 'vat_id' => null, 'contact_person' => null,
        ], $overrides);
    }
}
